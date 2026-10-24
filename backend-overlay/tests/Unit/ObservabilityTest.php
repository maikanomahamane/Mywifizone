<?php

namespace Tests\Unit;

use App\Domain\Observability\AlertRules;
use App\Domain\Observability\PrometheusText;
use App\Support\LogRedactor;
use PHPUnit\Framework\TestCase;

class ObservabilityTest extends TestCase
{
    public function test_prometheus_text_escapes_labels_and_rejects_bad_names(): void
    {
        $text = PrometheusText::render([
            ['name' => 'wifizone_x', 'help' => "aide\nligne", 'samples' => [['labels' => ['status' => "a\"b\\c\nd"], 'value' => 3], ['value' => 1.5]]],
            ['name' => 'bad name{', 'help' => 'x', 'samples' => [['value' => 1]]],
        ]);

        $this->assertStringContainsString('# HELP wifizone_x aide\\nligne', $text);
        $this->assertStringContainsString('wifizone_x{status="a\\"b\\\\c\\nd"} 3', $text);
        $this->assertStringContainsString("wifizone_x 1.5\n", $text);
        $this->assertStringNotContainsString('bad name', $text);
    }

    public function test_alert_rules(): void
    {
        $t = ['needs_attention_max' => 0, 'stuck_payments_max' => 0, 'failed_jobs_max' => 0, 'sync_failed_max' => 0, 'queue_size_max' => 500, 'commands_pending_old_max' => 0, 'offline_ratio_max' => 0.5];

        $this->assertSame([], AlertRules::evaluate([], $t));

        $alerts = AlertRules::evaluate(['portal_orders_needs_attention' => 2, 'payments_stuck' => 1, 'queue_size' => 600, 'routers_online' => 1, 'routers_offline' => 3], $t);
        $rules = array_column($alerts, 'severity', 'rule');
        $this->assertSame(AlertRules::CRITICAL, $rules['portal_orders_need_attention']);
        $this->assertSame(AlertRules::CRITICAL, $rules['payments_stuck']);
        $this->assertSame(AlertRules::WARNING, $rules['queue_backlog']);
        $this->assertSame(AlertRules::WARNING, $rules['routers_offline']);

        $this->assertSame([], AlertRules::evaluate(['routers_online' => 3, 'routers_offline' => 1, 'queue_size' => 499], $t), 'sous les seuils : silence');
    }

    public function test_log_redactor_masks_secrets_recursively(): void
    {
        $out = LogRedactor::redact(['user_id' => 'u1', 'password' => 'x', 'nested' => ['api_key' => 'k', 'Authorization' => 'Bearer z', 'ok' => 1], 'code' => 'ABCD1234', 'voucher_code' => 'Z', 'status' => 200]);

        $this->assertSame('u1', $out['user_id']);
        $this->assertSame(200, $out['status']);
        $this->assertSame('[redacted]', $out['password']);
        $this->assertSame('[redacted]', $out['code']);
        $this->assertSame('[redacted]', $out['voucher_code']);
        $this->assertSame('[redacted]', $out['nested']['api_key']);
        $this->assertSame('[redacted]', $out['nested']['Authorization']);
        $this->assertSame(1, $out['nested']['ok']);
    }
}
