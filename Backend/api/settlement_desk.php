<?php
// settlement_desk.php
//
// A bank's side of VouchMorph settlement. Shared by ZuruBank, ABSA (on
// ZuruBank's server) and SaccusSalis; each passes in how to reach its own
// account tables.
//
// 1. receiveAdvice(): VouchMorph's signed advice for a cycle lists what this
//    bank owes each receiving bank (and VouchMorph's fees), with the swaps
//    behind each line. Each line is paid once, from this bank's VouchMorph
//    clearing account (VMCLR-<BANK>):
//      - to another bank: submitted to the central bank with the line
//        reference as reference_code (money moves between settlement
//        accounts; the receiving bank is notified);
//      - to an account at this same bank ("on-us", e.g. VouchMorph's fee
//        account here): booked internally, no central bank.
// 2. recordReceipt(): when the central bank notifies that a payment arrived,
//    the receipt is recorded against its reference.
// 3. check(): VouchMorph asks "was line X paid to you?"; answered from the
//    receipts, with the central bank transfer as proof.
//
// Requests from VouchMorph must carry its certificate and signature, be
// signed by VOUCHMORPH, and be less than five minutes old.

final class SettlementDesk
{
    private const LINE_REF = '/^VM\d{10}-[A-Z0-9]{1,8}-[PF]\d*$/';

    /**
     * @param array $store callables for this bank's books:
     *   ensure_account(string $no, string $name): void   create an internal account if missing
     *   move(string $no, float $delta): void              change a balance (row locked); throws if missing
     *   log(array $entry): void                           write the bank's own transaction record
     */
    public function __construct(
        private PDO $pdo,
        private string $bank,
        private array $store,
        private string $centralSecret,
        private string $callbackUrl
    ) {}

    public static function clearingAccount(string $bank): string { return 'VMCLR-' . $bank; }
    public static function feeAccount(): string { return getenv('VOUCHMORPH_FEE_ACCOUNT') ?: 'VOUCHMORPH-FEES'; }
    public static function isInternal(string $account): bool {
        return str_starts_with($account, 'VMCLR-') || $account === self::feeAccount();
    }

    /** Returns null if the request is genuinely from VouchMorph, otherwise the reason. */
    public static function verifyVouchMorph(array $input, object $certManager): ?string
    {
        if (empty($input['certificate']) || empty($input['signature'])) return 'Certificate and signature required';
        if (($input['requester'] ?? '') !== 'VOUCHMORPH') return 'Requester must be VOUCHMORPH';
        $v = $certManager->verifySignedRequest($input);
        if (empty($v['verified'])) return 'Signature not verified: ' . ($v['message'] ?? 'unknown');
        $ts = (int)($input['timestamp'] ?? 0);
        if ($ts === 0 || abs(time() - $ts) > 300) return 'Request timestamp missing or more than 5 minutes off';
        return null;
    }

    public function ensureTables(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS settlement_advice_inbox (
                line_ref            VARCHAR(30) PRIMARY KEY,
                bank_code           VARCHAR(20) NOT NULL,
                advice_id           VARCHAR(40) NOT NULL,
                kind                VARCHAR(10) NOT NULL,
                creditor_bank       VARCHAR(20) NOT NULL,
                creditor_account    VARCHAR(64) NOT NULL,
                amount              NUMERIC(15,2) NOT NULL,
                status              VARCHAR(20) NOT NULL,
                central_transfer_id BIGINT,
                error               TEXT,
                line                JSONB,
                created_at          TIMESTAMP NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMP NOT NULL DEFAULT NOW()
            )
        ");
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS settlement_receipts (
                reference     VARCHAR(64) NOT NULL,
                bank_code     VARCHAR(20) NOT NULL,
                amount        NUMERIC(15,2) NOT NULL,
                from_bank     VARCHAR(20),
                to_account    VARCHAR(64),
                source        VARCHAR(12) NOT NULL,         -- CENTRAL_BANK or ON_US
                proof         VARCHAR(100),                 -- CB-<transfer id> or ON-US-<bank>
                received_at   TIMESTAMP NOT NULL DEFAULT NOW(),
                PRIMARY KEY (bank_code, reference)
            )
        ");
    }

    public function receiveAdvice(array $advice): array
    {
        $this->ensureTables();
        if (($advice['message_type'] ?? '') !== 'SETTLEMENT_ADVICE') return $this->fail(400, 'Not a settlement advice');
        if (($advice['debtor_bank'] ?? '') !== $this->bank) return $this->fail(400, "Advice is for {$advice['debtor_bank']}, not {$this->bank}");
        $lines = $advice['lines'] ?? null;
        if (!is_array($lines) || !$lines || empty($advice['advice_id'])) return $this->fail(400, 'Advice has no lines');

        // Validate every line, and that the lines add up, before paying any.
        $sum = 0.0;
        foreach ($lines as $l) {
            if (!preg_match(self::LINE_REF, (string)($l['line_ref'] ?? ''))) return $this->fail(400, 'Bad line reference ' . ($l['line_ref'] ?? '?'));
            if (!in_array($l['kind'] ?? '', ['PRINCIPAL', 'FEE'], true)) return $this->fail(400, "Bad kind on {$l['line_ref']}");
            if (!is_numeric($l['amount'] ?? null) || (float)$l['amount'] <= 0) return $this->fail(400, "Bad amount on {$l['line_ref']}");
            if (empty($l['creditor_bank']) || empty($l['creditor_account'])) return $this->fail(400, "No payee on {$l['line_ref']}");
            $itemSum = array_sum(array_map(fn($i) => (float)($i['amount'] ?? 0), (array)($l['items'] ?? [])));
            if (abs($itemSum - (float)$l['amount']) > 0.005) return $this->fail(400, "Items on {$l['line_ref']} do not add up to its amount");
            $sum += (float)$l['amount'];
        }
        if (abs($sum - (float)($advice['total_amount'] ?? -1)) > 0.005) return $this->fail(400, 'Lines do not add up to the advice total');

        $clearing = self::clearingAccount($this->bank);
        ($this->store['ensure_account'])($clearing, "VouchMorph clearing ({$this->bank})");
        $results = [];
        foreach ($lines as $l) {
            $results[] = $this->payLine($advice['advice_id'], $l, $clearing);
        }
        $ok = !array_filter($results, fn($r) => in_array($r['status'], ['FAILED'], true));
        return ['success' => $ok, 'advice_id' => $advice['advice_id'], 'lines' => $results,
                'message' => $ok ? 'Advice accepted; lines paid or submitted to the central bank' : 'Some lines could not be paid'];
    }

    private function payLine(string $adviceId, array $l, string $clearing): array
    {
        $ref = $l['line_ref'];
        $amount = round((float)$l['amount'], 2);
        $prev = $this->pdo->prepare("SELECT status, central_transfer_id FROM settlement_advice_inbox WHERE line_ref = ?");
        $prev->execute([$ref]);
        if ($p = $prev->fetch(PDO::FETCH_ASSOC)) {
            if ($p['status'] !== 'FAILED') return ['line_ref' => $ref, 'status' => $p['status'], 'duplicate' => true, 'central_transfer_id' => $p['central_transfer_id']];
            $this->pdo->prepare("DELETE FROM settlement_advice_inbox WHERE line_ref = ? AND status = 'FAILED'")->execute([$ref]);   // retry a failed line
        }

        $onUs = $l['creditor_bank'] === $this->bank;
        try {
            $this->pdo->beginTransaction();
            $this->pdo->prepare("
                INSERT INTO settlement_advice_inbox (line_ref, bank_code, advice_id, kind, creditor_bank, creditor_account, amount, status, line)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'RECEIVED', ?::jsonb)
            ")->execute([$ref, $this->bank, $adviceId, $l['kind'], $l['creditor_bank'], $l['creditor_account'], $amount, json_encode($l)]);
            ($this->store['move'])($clearing, -$amount);

            if ($onUs) {
                ($this->store['ensure_account'])($l['creditor_account'], 'VouchMorph fees');
                ($this->store['move'])($l['creditor_account'], $amount);
                ($this->store['log'])(['reference' => $ref, 'from' => $clearing, 'to' => $l['creditor_account'], 'amount' => $amount,
                                       'status' => 'completed', 'direction' => 'internal', 'note' => "VouchMorph settlement {$l['kind']} {$ref} (on-us)"]);
                $this->recordReceipt($ref, $amount, $this->bank, $l['creditor_account'], 'ON_US', 'ON-US-' . $this->bank);
                $this->pdo->prepare("UPDATE settlement_advice_inbox SET status = 'PAID_ON_US', updated_at = NOW() WHERE line_ref = ?")->execute([$ref]);
                $this->pdo->commit();
                return ['line_ref' => $ref, 'status' => 'PAID_ON_US'];
            }

            ($this->store['log'])(['reference' => $ref, 'from' => $clearing, 'to' => $l['creditor_account'], 'amount' => $amount,
                                   'status' => 'pending', 'direction' => 'out', 'note' => "VouchMorph settlement {$l['kind']} {$ref} to {$l['creditor_bank']}"]);
            [$ok, $resp] = $this->submitToCentralBank($ref, $adviceId, $clearing, $l['creditor_bank'], $l['creditor_account'], $amount);
            if (!$ok) throw new RuntimeException('Central bank refused: ' . ($resp['message'] ?? 'no reply'));
            $this->pdo->prepare("UPDATE settlement_advice_inbox SET status = 'SUBMITTED', central_transfer_id = ?, updated_at = NOW() WHERE line_ref = ?")
                ->execute([(int)($resp['transfer_id'] ?? 0) ?: null, $ref]);
            $this->pdo->commit();
            return ['line_ref' => $ref, 'status' => 'SUBMITTED', 'central_transfer_id' => $resp['transfer_id'] ?? null];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            // Nothing was debited (rolled back); remember the failure so VouchMorph's resend retries it.
            $this->pdo->prepare("
                INSERT INTO settlement_advice_inbox (line_ref, bank_code, advice_id, kind, creditor_bank, creditor_account, amount, status, error, line)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'FAILED', ?, ?::jsonb)
                ON CONFLICT (line_ref) DO UPDATE SET status = 'FAILED', error = EXCLUDED.error, updated_at = NOW()
            ")->execute([$ref, $this->bank, $adviceId, $l['kind'], $l['creditor_bank'], $l['creditor_account'], $amount, substr($e->getMessage(), 0, 500), json_encode($l)]);
            error_log("[settlement_desk] {$this->bank} line {$ref} failed: " . $e->getMessage());
            return ['line_ref' => $ref, 'status' => 'FAILED', 'error' => $e->getMessage()];
        }
    }

    private function submitToCentralBank(string $ref, string $adviceId, string $fromAccount, string $toBank, string $toAccount, float $amount): array
    {
        if ($this->centralSecret === '') return [false, ['message' => 'central bank signing secret not configured']];
        $payload = [
            'sender_bank_code' => $this->bank,
            'sender_account' => $fromAccount,
            'recipient_bank_code' => $toBank,
            'recipient_account' => $toAccount,
            'amount' => $amount,
            'reference_code' => $ref,
            'origin_transaction_id' => $adviceId,
            'origin_callback_url' => $this->callbackUrl,
            'timestamp' => time(),
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $url = rtrim(getenv('CENTRAL_BANK_URL') ?: 'https://centralbank-production.up.railway.app', '/') . '/api/submit_transfer.php';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => $raw,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: HMAC ' . $this->bank . ':' . hash_hmac('sha256', $raw, $this->centralSecret)],
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $resp = json_decode((string)$body, true) ?: ['message' => $err ?: "HTTP {$code}"];
        return [!$err && in_array($code, [200, 202], true) && !empty($resp['success']), $resp];
    }

    public function recordReceipt(string $ref, float $amount, string $fromBank, string $toAccount, string $source, string $proof): void
    {
        $this->ensureTables();
        $this->pdo->prepare("
            INSERT INTO settlement_receipts (reference, bank_code, amount, from_bank, to_account, source, proof)
            VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT (bank_code, reference) DO NOTHING
        ")->execute([$ref, $this->bank, round($amount, 2), $fromBank, $toAccount, $source, $proof]);
    }

    /** Updates our own advice line when the central bank reports on a payment we made. */
    public function onSenderNotice(array $n): void
    {
        $this->ensureTables();
        $status = ($n['status'] ?? '') === 'approved' ? 'SETTLED' : (($n['status'] ?? '') === 'rejected' ? 'REJECTED' : null);
        if ($status) {
            $this->pdo->prepare("UPDATE settlement_advice_inbox SET status = ?, updated_at = NOW() WHERE line_ref = ? AND bank_code = ?")
                ->execute([$status, (string)($n['reference_code'] ?? ''), $this->bank]);
        }
    }

    public function check(array $req): array
    {
        $this->ensureTables();
        $ref = (string)($req['reference'] ?? '');
        if ($ref === '') return $this->fail(400, 'reference required');
        $stmt = $this->pdo->prepare("SELECT amount, from_bank, source, proof, received_at FROM settlement_receipts WHERE bank_code = ? AND reference = ?");
        $stmt->execute([$this->bank, $ref]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['success' => true, 'settled' => false, 'reference' => $ref, 'message' => "No payment with reference {$ref} received by {$this->bank} yet"];
        return ['success' => true, 'settled' => true, 'reference' => $ref, 'settlement_reference' => $r['proof'],
                'amount' => number_format((float)$r['amount'], 2, '.', ''), 'from_bank' => $r['from_bank'],
                'received_at' => $r['received_at'], 'message' => "Received by {$this->bank} via " . ($r['source'] === 'ON_US' ? 'internal transfer' : 'central bank')];
    }

    private function fail(int $code, string $message): array
    {
        http_response_code($code);
        return ['success' => false, 'settled' => false, 'message' => $message];
    }
}
