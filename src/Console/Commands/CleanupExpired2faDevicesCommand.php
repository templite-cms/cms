<?php

namespace Templite\Cms\Console\Commands;

use Illuminate\Console\Command;
use Templite\Cms\Services\TwoFactorService;

class CleanupExpired2faDevicesCommand extends Command
{
    protected $signature = 'cms:cleanup-expired-2fa-devices';
    protected $description = 'Очистка истёкших доверенных устройств 2FA';

    public function handle(TwoFactorService $twoFactor): int
    {
        $twoFactor->cleanupExpiredDevices();

        $this->info('Очистка доверенных устройств 2FA выполнена.');

        return self::SUCCESS;
    }
}
