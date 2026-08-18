<?php

namespace Tests\Feature\Operations;

use Tests\TestCase;

class OperationalDeploymentConfigurationTest extends TestCase
{
    public function test_ubuntu_templates_use_one_cron_scheduler_trigger_without_rotating_laravel_daily_logs(): void
    {
        $guide = (string) file_get_contents(base_path('docs/deployment/empty-ubuntu-vps-guide.md'));
        $logrotate = (string) file_get_contents(base_path('deploy/ubuntu/ToutDispo-logrotate.conf'));
        $verification = (string) file_get_contents(base_path('scripts/verify-ubuntu-operations.sh'));

        self::assertSame(1, substr_count($guide, '* * * * * cd /var/www/ToutDispo/current && /usr/bin/php artisan schedule:run'));
        self::assertStringContainsString('grep -F "$APP_PATH"', $verification);
        self::assertStringContainsString('crontab -u "$DEPLOY_USER"', $verification);
        self::assertStringContainsString('ToutDispo-scheduler.service', $verification);
        self::assertStringNotContainsString('storage/logs', $logrotate);
        self::assertStringContainsString('/var/log/passion/*.log', $logrotate);
    }
}
