<?php

namespace App\Services\EGov;

use App\Models\AuditEvent;
use App\Models\CaseDocument;
use App\Models\GuaranteeLetter;
use App\Models\GuaranteeUtilization;
use App\Models\MedicalCase;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class EGovChainService
{
    /**
     * Placeholder contract address used only when the ledger is simulated.
     * Never presented as a real deployment.
     */
    private const SIMULATED_CONTRACT = '0x0000000000000000000000000000000000000000';

    protected string $rpcUrl;
    protected string $chainId;
    protected string $smartContractAddress;
    protected ?string $apiKey;

    public function __construct()
    {
        $this->rpcUrl = config('services.egov.chain.rpc_url', 'http://localhost:3000/egovph/egovchain');
        // Chain id comes from config only, so it can no longer disagree with
        // the value the mock eth_chainId reports.
        $this->chainId = (string) (config('services.egov.chain.chain_id') ?: '13371');
        $this->smartContractAddress = (string) (config('services.egov.chain.contract_address')
            ?: self::SIMULATED_CONTRACT);
        $this->apiKey = config('services.egov.chain.api_key');
    }

    /**
     * True when no real ledger is reachable/configured, so anchoring only
     * produces locally-generated placeholders.
     */
    public function isSimulated(): bool
    {
        return EGovMode::isSandbox()
            || empty(config('services.egov.chain.contract_address'))
            || $this->smartContractAddress === self::SIMULATED_CONTRACT;
    }

    public function ledgerLabel(): string
    {
        return $this->isSimulated()
            ? 'Simulated ledger (no chain submission)'
            : 'eGovChain (Hyperledger Besu)';
    }

    public function contractAddress(): string
    {
        return $this->smartContractAddress;
    }

    public function chainId(): string
    {
        return $this->chainId;
    }

    /**
     * Link value used as the "previous hash" for the first audit event.
     */
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public function recordEvent(?MedicalCase $medicalCase, ?User $actor, string $action, string $description, array $metadata = []): AuditEvent
    {
        return DB::transaction(function () use ($medicalCase, $actor, $action, $description, $metadata) {
            $payload = json_encode([
                'case_id' => $medicalCase?->id,
                'actor_id' => $actor?->id,
                'action' => $action,
                'description' => $description,
                'metadata' => $metadata,
                'timestamp' => now()->toIso8601String(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $sha256 = hash('sha256', $payload);
            $txHash = '0x' . hash('sha256', $sha256 . time());

            // Chain each event to its predecessor. Previously the hash covered
            // only its own row and stored a 16-character prefix, so editing any
            // row left every hash still "valid" — the log was not tamper-evident.
            $previousHash = AuditEvent::query()
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('chain_hash') ?: self::GENESIS_HASH;

            $chainHash = hash('sha256', $previousHash . $sha256);

            return AuditEvent::create([
                'medical_case_id' => $medicalCase?->id,
                'actor_id' => $actor?->id,
                'actor_name' => $actor ? $actor->name : 'eGov System Adapter',
                'action' => $action,
                'description' => $description,
                'metadata' => array_merge($metadata, [
                    'besu_tx_hash' => $txHash,
                    'besu_contract' => $this->smartContractAddress,
                    'network' => $this->ledgerLabel(),
                    'ledger_simulated' => $this->isSimulated(),
                ]),
                'chain_hash' => $chainHash,
                'payload_sha256' => $sha256,
            ]);
        });
    }

    /**
     * Recompute the audit hash chain and report the first broken link.
     *
     * Returns verified=false plus the offending event id when a stored
     * chain_hash does not match the hash of (previous chain_hash + payload
     * digest), or when any event predates chaining and therefore cannot be
     * checked at all. A chain with unchecked links is not a verified chain.
     */
    public function verifyTimeline(?MedicalCase $medicalCase = null): array
    {
        $query = AuditEvent::query()->orderBy('id');
        if ($medicalCase) {
            $query->where('medical_case_id', $medicalCase->id);
        }

        $previousHash = self::GENESIS_HASH;
        $checked = 0;
        $unverifiable = [];

        foreach ($query->get() as $event) {
            if (empty($event->payload_sha256)) {
                $unverifiable[] = $event->id;
                // Re-seed the link so later events are still checked against
                // the hash this row actually carries.
                $previousHash = $event->chain_hash;
                continue;
            }

            $expected = hash('sha256', $previousHash . $event->payload_sha256);

            if (! hash_equals($expected, (string) $event->chain_hash)) {
                return [
                    'verified' => false,
                    'checked' => $checked,
                    'broken_at_event_id' => $event->id,
                    'broken_action' => $event->action,
                    'expected_hash' => $expected,
                    'stored_hash' => $event->chain_hash,
                    'unverifiable_event_ids' => $unverifiable,
                    'reason' => 'chain_link_mismatch',
                    'ledger_simulated' => $this->isSimulated(),
                ];
            }

            $previousHash = $event->chain_hash;
            $checked++;
        }

        if (! empty($unverifiable)) {
            return [
                'verified' => false,
                'checked' => $checked,
                'head_hash' => $previousHash,
                'unverifiable_event_ids' => $unverifiable,
                'reason' => 'events_predate_chaining',
                'ledger_simulated' => $this->isSimulated(),
            ];
        }

        return [
            'verified' => true,
            'checked' => $checked,
            'head_hash' => $previousHash,
            'unverifiable_event_ids' => [],
            'reason' => null,
            'ledger_simulated' => $this->isSimulated(),
        ];
    }

    /**
     * Full SHA-256 digest for a document's content.
     *
     * Returns the complete 64-character hash. It previously returned only the
     * first 16 hex characters prefixed with "DOC-HASH-", which is a 64-bit
     * truncation and not a usable integrity guarantee.
     */
    public function generateDocumentHash(string $content): string
    {
        return hash('sha256', $content);
    }

    /**
     * Anchors a record on eGovChain.
     *
     * When the ledger is simulated (no contract address, sandbox mode) this
     * returns locally-generated placeholders explicitly marked
     * `simulated => true` — it must not be read as a chain submission.
     *
     * In live mode it performs a real JSON-RPC call and returns the node's
     * answer. If the node is unreachable or unconfigured it reports
     * `anchored => false`; it never fabricates a transaction hash.
     */
    public function anchorRecordOnBesu(string $recordId, string $payloadHash, string $recordType = 'GUARANTEE_LETTER'): array
    {
        if (! $this->isSimulated()) {
            $result = $this->submitAnchorToNode($recordId, $payloadHash, $recordType);

            if ($result !== null) {
                return $result;
            }

            return [
                'jsonrpc' => '2.0',
                'id' => 1,
                'anchored' => false,
                'simulated' => false,
                'error' => [
                    'code' => -32603,
                    'message' => 'eGovChain node did not confirm the anchor; no transaction was recorded.',
                ],
            ];
        }

        $blockNumber = Cache::get('egov_chain_block', 1842000);
        Cache::put('egov_chain_block', $blockNumber + 1);
        $txHash = '0x' . strtolower(hash('sha256', $recordId . $payloadHash . microtime()));
        $blockHash = '0x' . strtolower(hash('sha256', 'block_' . $blockNumber));

        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            // Explicit: nothing was submitted to any chain.
            'anchored' => false,
            'simulated' => true,
            'result' => [
                'status' => '0x1',
                'transactionHash' => $txHash,
                'transactionIndex' => '0x1',
                'blockHash' => $blockHash,
                'blockNumber' => '0x' . dechex($blockNumber),
                'from' => '0x0000000000000000000000000000000000000000',
                'to' => $this->smartContractAddress,
                'gasUsed' => '0x0',
                'cumulativeGasUsed' => '0x0',
                'contractAddress' => null,
                'logs' => [
                    [
                        'address' => $this->smartContractAddress,
                        'topics' => [
                            '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
                            '0x' . str_pad(substr(hash('sha256', $recordId), 0, 64), 64, '0', STR_PAD_LEFT),
                        ],
                        'data' => '0x' . bin2hex(json_encode(['record_id' => $recordId, 'hash' => $payloadHash, 'type' => $recordType])),
                    ],
                ],
                'chain_name' => $this->ledgerLabel(),
                'consensus' => 'none (simulated)',
                'chain_id' => $this->chainId,
            ],
        ];
    }

    /**
     * Real submission path. Returns null when the node could not be reached or
     * did not return a transaction hash.
     */
    private function submitAnchorToNode(string $recordId, string $payloadHash, string $recordType): ?array
    {
        $endpoint = rtrim($this->rpcUrl, '/');
        if ($this->apiKey) {
            $endpoint .= '/' . ltrim($this->apiKey, '/');
        }

        try {
            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->timeout(20)
                ->post($endpoint, [
                    'jsonrpc' => '2.0',
                    'method' => 'egov_anchorRecord',
                    'params' => [$recordId, $payloadHash, $recordType],
                    'id' => 1,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $json = $response->json();
            if (! is_array($json) || empty($json['result']['transactionHash'])) {
                return null;
            }

            $json['anchored'] = true;
            $json['simulated'] = false;

            return $json;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Hyperledger Besu JSON-RPC Method: egov_verifyRecord / eth_call
     */
    public function verifyRecordOnBesu(string $txHashOrRecordId): array
    {
        if (! $this->isSimulated()) {
            $endpoint = rtrim($this->rpcUrl, '/');
            if ($this->apiKey) {
                $endpoint .= '/' . ltrim($this->apiKey, '/');
            }

            try {
                $response = Http::withHeaders(['Content-Type' => 'application/json'])
                    ->timeout(20)
                    ->post($endpoint, [
                        'jsonrpc' => '2.0',
                        'method' => 'egov_verifyRecord',
                        'params' => [$txHashOrRecordId],
                        'id' => 1,
                    ]);

                if ($response->successful() && is_array($response->json())) {
                    $json = $response->json();
                    $json['simulated'] = false;

                    return $json;
                }
            } catch (\Throwable $e) {
                // fall through to the failure report below
            }

            return [
                'jsonrpc' => '2.0',
                'id' => 1,
                'simulated' => false,
                'error' => [
                    'code' => -32603,
                    'message' => 'eGovChain node could not verify this record.',
                ],
                'result' => ['verified' => false],
            ];
        }

        // A hard-coded verified:true was meaningless here: nothing was ever
        // submitted to a chain, so there is nothing to verify.
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'verified' => false,
                'simulated' => true,
                'state' => 'NOT_ANCHORED',
                'contract' => $this->smartContractAddress,
                'ledger_timestamp' => null,
                'tamper_evident' => false,
                'chain_id' => $this->chainId,
                'note' => 'No real ledger is configured; anchoring is simulated and cannot be verified on-chain.',
            ],
        ];
    }

    /**
     * Standard JSON-RPC handler dispatcher for Besu RPC proxy
     */
    public function handleJsonRpc(array $request): array
    {
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? 1;

        if (! $this->isSimulated()) {
            $endpoint = rtrim($this->rpcUrl, '/');
            if ($this->apiKey) {
                $endpoint .= '/' . ltrim($this->apiKey, '/');
            }

            try {
                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                ])->timeout(20)->post($endpoint, [
                    'jsonrpc' => '2.0',
                    'method' => $method,
                    'params' => $params,
                    'id' => $id,
                ]);

                if ($response->successful()) {
                    $json = $response->json();
                    if ($json !== null) {
                        return $json;
                    }
                }

                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => [
                        'code' => -32603,
                        'message' => "eGovChain node did not answer {$method}.",
                    ],
                ];
            } catch (\Throwable $e) {
                // Live mode must never fall through to canned data.
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'error' => [
                        'code' => -32603,
                        'message' => "eGovChain node unreachable for {$method}.",
                    ],
                ];
            }
        }

        switch ($method) {
            // --- Misc ---
            case 'rpc_modules':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'txpool' => '1.0',
                        'trace' => '1.0',
                        'debug' => '1.0',
                        'eth' => '1.0',
                        'web3' => '1.0',
                        'admin' => '1.0',
                        'qbft' => '1.0',
                        'net' => '1.0',
                    ],
                ];

            // --- WEB3 ---
            case 'web3_clientVersion':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => 'besu/v24.12.2/linux-x86_64/openjdk-java-21',
                ];
            case 'web3_sha3':
                $data = $params[0] ?? '0x68656c6c6f';
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => '0x1c8aff950685c2ed4bc3174f3472287b56d9517b9c948127319a09a7a36deac8',
                ];

            // --- NET ---
            case 'net_version':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => $this->chainId,
                ];
            case 'net_listening':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => true,
                ];
            case 'net_peerCount':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => '0x3',
                ];
            case 'net_enode':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => 'enode://a69063a0c99996293ecfd726969a940083d6898c41acda1a43a08687acaa1e1203d5d0caf6ec90ac29302a693e6012daf84a0135a79304ee27713a897a31bbf0@0.0.0.0:30303',
                ];
            case 'net_services':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'jsonrpc' => ['host' => '0.0.0.0', 'port' => '8545'],
                        'ws' => ['host' => '0.0.0.0', 'port' => '8546'],
                        'p2p' => ['host' => '0.0.0.0', 'port' => '30303'],
                    ],
                ];

            // --- ETH Chain / Gas ---
            case 'eth_chainId':
                // Derived from config so it can no longer disagree with
                // services.egov.chain.chain_id (it previously reported 0x343b /
                // 13371 while config said 2026).
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => '0x' . dechex((int) $this->chainId),
                ];
            case 'eth_protocolVersion':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => '0x44',
                ];
            case 'eth_syncing':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => false,
                ];
            case 'eth_coinbase':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => '0x0c540e535119dbef69fc75a96034b4270149a7c4',
                ];
            case 'eth_mining':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => true,
                ];
            case 'eth_hashrate':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => '0x0',
                ];
            case 'eth_gasPrice':
            case 'eth_maxPriorityFeePerGas':
            case 'eth_blobBaseFee':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => '0x0', // Zero fees
                ];
            case 'eth_blockNumber':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => '0x404f0',
                ];

            // --- ETH Accounts / State ---
            case 'eth_accounts':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        '0x95222290DD7278Aa3Ddd389Cc1E1d165CC4BAfe5',
                    ],
                ];
            case 'eth_getBalance':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => '0x1000000000000000000',
                ];
            case 'eth_getTransactionCount':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => '0x1',
                ];
            case 'eth_getCode':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => '0x',
                ];

            // --- ETH Blocks ---
            case 'eth_getBlockByNumber':
            case 'eth_getBlockByHash':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'number' => '0x404f0',
                        'hash' => '0x7b2f91a08e4c19d205f3189a04b12c5e5264b9b74070a2a4b87da64101e4917a',
                        'parentHash' => '0x6a1f81a08e4c19d205f3189a04b12c5e5264b9b74070a2a4b87da64101e4917a',
                        'gasLimit' => '0x1fffffffffffff',
                        'gasUsed' => '0x0',
                        'miner' => '0x0c540e535119dbef69fc75a96034b4270149a7c4',
                        'transactions' => [],
                    ],
                ];

            // --- ETH Transactions ---
            case 'eth_getTransactionReceipt':
                $txHash = $params[0] ?? '0xd8f2910c5d12a8f9104b2819c5b201f8';
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'transactionHash' => $txHash,
                        'blockNumber' => '0x404f0',
                        'status' => '0x1',
                        'gasUsed' => '0x0',
                    ],
                ];
            case 'eth_getTransactionByHash':
                $txHash = $params[0] ?? '0xd8f2910c5d12a8f9104b2819c5b201f8';
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'hash' => $txHash,
                        'blockNumber' => '0x404f0',
                        'from' => '0x95222290DD7278Aa3Ddd389Cc1E1d165CC4BAfe5',
                        'to' => $this->smartContractAddress,
                        'gasPrice' => '0x0',
                    ],
                ];
            case 'eth_sendRawTransaction':
            case 'egov_anchorRecord':
                $recordId = $params[0] ?? ('REC-' . rand(1000, 9999));
                $hash = $params[1] ?? hash('sha256', (string) $recordId);
                return $this->anchorRecordOnBesu((string) $recordId, (string) $hash);

            // --- ETH Call / Estimate & Contracts ---
            case 'eth_call':
            case 'egov_verifyRecord':
                $to = $params[0]['to'] ?? ($params[0] ?? '');
                $data = $params[0]['data'] ?? '';

                // HackathonGuestbook signature simulations
                if (str_starts_with($data, '0x7d0a5142')) {
                    // teamCount() -> returns 1
                    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => '0x0000000000000000000000000000000000000000000000000000000000000001'];
                }
                if (str_starts_with($data, '0x37ea1b74')) {
                    // entryCount() -> returns 1
                    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => '0x0000000000000000000000000000000000000000000000000000000000000001'];
                }

                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => '0x',
                ];
            case 'eth_estimateGas':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => '0x5208', // 21000
                ];

            // --- TxPool ---
            case 'txpool_besuStatistics':
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'maxSize' => -1,
                        'localCount' => 0,
                        'remoteCount' => 5,
                    ],
                ];

            default:
                return [
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'status' => 'active',
                        'network' => $this->ledgerLabel(),
                        'chainId' => (int) $this->chainId,
                        'zero_fee' => true,
                    ],
                ];
        }
    }


    /**
     * Summarises an anchor result for storage. `chain_anchored` reflects what
     * actually happened instead of being hard-coded true.
     */
    private function anchorMetadata(array $anchor, array $extra = []): array
    {
        return array_merge($extra, [
            'chain_anchored' => (bool) ($anchor['anchored'] ?? false),
            'ledger_simulated' => (bool) ($anchor['simulated'] ?? false),
            'anchor_tx_hash' => $anchor['result']['transactionHash'] ?? null,
        ]);
    }

    public function anchorCaseTransition(MedicalCase $case, string $fromState, string $toState, ?User $actor, array $extraMeta = []): AuditEvent
    {
        $payloadHash = hash('sha256', json_encode(['case_id' => $case->id, 'from' => $fromState, 'to' => $toState, 'meta' => $extraMeta]));
        $anchor = $this->anchorRecordOnBesu('CASE-' . $case->id, $payloadHash, 'CASE_STATE_TRANSITION');

        return $this->recordEvent(
            $case,
            $actor,
            'STATE_TRANSITION',
            "Transitioned from {$fromState} to {$toState}",
            $this->anchorMetadata($anchor, $extraMeta)
        );
    }

    public function anchorDocumentCertification(CaseDocument $doc, User $certifier): AuditEvent
    {
        $payloadHash = hash('sha256', json_encode(['document_id' => $doc->id, 'certifier_id' => $certifier->id]));
        $anchor = $this->anchorRecordOnBesu('DOC-' . $doc->id, $payloadHash, 'DOCUMENT_CERTIFICATION');

        return $this->recordEvent(
            $doc->medicalCase,
            $certifier,
            'DOCUMENT_CERTIFIED',
            "Document {$doc->document_type} certified",
            $this->anchorMetadata($anchor, ['document_id' => $doc->id])
        );
    }

    public function anchorGuaranteeLetter(GuaranteeLetter $gl, User $issuer): AuditEvent
    {
        $payloadHash = hash('sha256', json_encode(['gl_number' => $gl->gl_number, 'approved_amount' => $gl->approved_amount]));
        $anchor = $this->anchorRecordOnBesu('GL-' . $gl->id, $payloadHash, 'GUARANTEE_LETTER_ISSUANCE');

        return $this->recordEvent(
            $gl->medicalCase,
            $issuer,
            'GUARANTEE_LETTER_ISSUED',
            "Guarantee Letter {$gl->gl_number} issued for amount {$gl->approved_amount}",
            $this->anchorMetadata($anchor, ['gl_number' => $gl->gl_number, 'approved_amount' => $gl->approved_amount])
        );
    }

    public function anchorGuaranteeUtilization(GuaranteeUtilization $util, User $recorder): AuditEvent
    {
        $payloadHash = hash('sha256', json_encode(['utilization_id' => $util->id, 'amount' => $util->amount_utilized]));
        $anchor = $this->anchorRecordOnBesu('UTIL-' . $util->id, $payloadHash, 'GUARANTEE_UTILIZATION');

        return $this->recordEvent(
            $util->guaranteeLetter?->medicalCase,
            $recorder,
            'UTILIZATION_RECORDED',
            "Recorded utilization of {$util->amount_utilized}",
            $this->anchorMetadata($anchor, ['utilization_id' => $util->id, 'amount' => $util->amount_utilized])
        );
    }
}
