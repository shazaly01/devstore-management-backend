<?php

namespace App\Console\Commands;

use App\Services\LicenseService;
use Illuminate\Console\Command;

class GetHardwareIdCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'license:hardware-id';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'استخراج وعرض بصمة العتاد الفريدة الخاصة بهذا الجهاز';

    /**
     * Execute the console command.
     */
    public function handle(LicenseService $licenseService): int
    {
        $hardwareId = $licenseService->getHardwareIdentifier();

        $this->newLine();
        $this->info('====================================================');
        $this->info('           بصمة عتاد هذا الجهاز (Hardware ID)        ');
        $this->info('====================================================');
        $this->newLine();
        $this->line("  كود العتاد:  <comment>{$hardwareId}</comment>");
        $this->newLine();
        $this->line('انسخ هذا الكود واستخدمه عند توليد كود الترخيص الخاص بهذا العميل.');
        $this->info('====================================================');
        $this->newLine();

        return self::SUCCESS;
    }
}