<?php

declare(strict_types=1);

namespace Tudorsync\Woocommerce;

use Tudorsync\Woocommerce\Admin\Admin;
use Tudorsync\Woocommerce\Api\ApiLog;
use Tudorsync\Woocommerce\Api\ApiTester;
use Tudorsync\Woocommerce\Api\CallContext;
use Tudorsync\Woocommerce\Api\LoggingHttpClient;
use Tudorsync\Woocommerce\Api\WordPressHttpClient;
use Tudorsync\Woocommerce\Attribution\OrderAttribution;
use Tudorsync\Woocommerce\Attribution\UtmCapture;
use Tudorsync\Woocommerce\Attribution\UtmCookie;
use Tudorsync\Woocommerce\Catalog\CatalogConnector;
use Tudorsync\Woocommerce\Catalog\LocalizedUrls;
use Tudorsync\Woocommerce\Cli\Commands;
use Tudorsync\Woocommerce\Realtime\ChangeRecorder;
use Tudorsync\Woocommerce\Realtime\PendingQueue;
use Tudorsync\Woocommerce\Report\MonthlyReport;
use Tudorsync\Woocommerce\Report\PeriodRepository;
use Tudorsync\Woocommerce\Report\ProgrammeSalesSource;
use Tudorsync\Woocommerce\Report\ReportFiles;
use Tudorsync\Woocommerce\Sync\Lock;
use Tudorsync\Woocommerce\Sync\Status;
use Tudorsync\Woocommerce\Sync\SyncRunner;

/**
 * Builds the plugin's services (WordPress has no DI container) and hooks them into WordPress.
 * One instance per request, through Plugin::instance(); each service is created once.
 */
final class Plugin
{
    public const VERSION = '0.2.0';

    private static ?self $instance = null;

    /** @var array<string, object> */
    private array $services = [];

    private function __construct(
        public readonly string $pluginFile,
    ) {
    }

    public static function boot(string $pluginFile): self
    {
        return self::$instance ??= new self($pluginFile);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \LogicException('Tudorsync is not booted.');
        }

        return self::$instance;
    }

    public function register(): void
    {
        $this->schema()->upgradeIfNeeded();
        (new ProductField())->register();
        $this->changeRecorder()->register();
        $this->cron()->register();
        (new UtmCapture($this->config(), $this->pluginFile, self::VERSION))->register();
        (new OrderAttribution(new UtmCookie(), $this->logger()))->register();
        $this->programmeSalesSource()->register();

        if (is_admin()) {
            (new Admin($this))->register();
        }

        if (defined('WP_CLI') && WP_CLI) {
            Commands::register($this);
        }
    }

    public function config(): Config
    {
        return $this->get(Config::class, static fn (): Config => new Config());
    }

    public function logger(): Logger
    {
        return $this->get(Logger::class, static fn (): Logger => new Logger());
    }

    public function schema(): Schema
    {
        return $this->get(Schema::class, static fn (): Schema => new Schema());
    }

    public function status(): Status
    {
        return $this->get(Status::class, static fn (): Status => new Status());
    }

    public function callContext(): CallContext
    {
        return $this->get(CallContext::class, static fn (): CallContext => new CallContext());
    }

    public function apiLog(): ApiLog
    {
        return $this->get(ApiLog::class, static fn (): ApiLog => new ApiLog());
    }

    public function pendingQueue(): PendingQueue
    {
        return $this->get(PendingQueue::class, static fn (): PendingQueue => new PendingQueue());
    }

    public function modelCodeResolver(): ModelCodeResolver
    {
        return $this->get(ModelCodeResolver::class, fn (): ModelCodeResolver => new ModelCodeResolver($this->config()));
    }

    public function localizedUrls(): LocalizedUrls
    {
        return $this->get(LocalizedUrls::class, fn (): LocalizedUrls => new LocalizedUrls($this->config()));
    }

    public function catalogConnector(): CatalogConnector
    {
        return $this->get(CatalogConnector::class, fn (): CatalogConnector => new CatalogConnector(
            $this->config(),
            $this->modelCodeResolver(),
            $this->localizedUrls(),
        ));
    }

    public function httpClient(): LoggingHttpClient
    {
        return $this->get(LoggingHttpClient::class, fn (): LoggingHttpClient => new LoggingHttpClient(
            new WordPressHttpClient(),
            $this->apiLog(),
            $this->callContext(),
            $this->config(),
            $this->logger(),
        ));
    }

    public function syncRunner(): SyncRunner
    {
        return $this->get(SyncRunner::class, fn (): SyncRunner => new SyncRunner(
            $this->config(),
            $this->catalogConnector(),
            $this->status(),
            $this->logger(),
            $this->httpClient(),
            $this->callContext(),
            $this->pendingQueue(),
            new Lock(),
        ));
    }

    public function apiTester(): ApiTester
    {
        return $this->get(ApiTester::class, fn (): ApiTester => new ApiTester(
            $this->config(),
            $this->catalogConnector(),
            $this->httpClient(),
            $this->callContext(),
            $this->apiLog(),
        ));
    }

    public function changeRecorder(): ChangeRecorder
    {
        return $this->get(ChangeRecorder::class, fn (): ChangeRecorder => new ChangeRecorder(
            $this->config(),
            $this->modelCodeResolver(),
            $this->pendingQueue(),
            $this->logger(),
        ));
    }

    public function cron(): Cron
    {
        return $this->get(Cron::class, fn (): Cron => new Cron($this));
    }

    public function programmeSalesSource(): ProgrammeSalesSource
    {
        return $this->get(ProgrammeSalesSource::class, fn (): ProgrammeSalesSource => new ProgrammeSalesSource(
            $this->config(),
            $this->modelCodeResolver(),
        ));
    }

    public function periodRepository(): PeriodRepository
    {
        return $this->get(PeriodRepository::class, static fn (): PeriodRepository => new PeriodRepository());
    }

    public function reportFiles(): ReportFiles
    {
        return $this->get(ReportFiles::class, static fn (): ReportFiles => new ReportFiles());
    }

    public function monthlyReport(): MonthlyReport
    {
        return $this->get(MonthlyReport::class, fn (): MonthlyReport => new MonthlyReport(
            $this->programmeSalesSource(),
            $this->periodRepository(),
            $this->reportFiles(),
            $this->config(),
        ));
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @param callable(): T $factory
     * @return T
     */
    private function get(string $id, callable $factory): object
    {
        return $this->services[$id] ??= $factory();
    }
}
