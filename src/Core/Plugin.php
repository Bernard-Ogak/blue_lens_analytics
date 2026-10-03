<?php
/**
 * Plugin root: owns the container and boots services.
 *
 * @package BlueLens\Analytics
 */

declare( strict_types=1 );

namespace BlueLens\Analytics\Core;

use BlueLens\Analytics\Admin\Dashboard;
use BlueLens\Analytics\Admin\Menu;
use BlueLens\Analytics\Admin\Rest\ReportsController;
use BlueLens\Analytics\Admin\Rest\SettingsController;
use BlueLens\Analytics\Aggregation\Aggregator;
use BlueLens\Analytics\Aggregation\Reports;
use BlueLens\Analytics\Aggregation\Retention;
use BlueLens\Analytics\Core\Migrations\Migrator;
use BlueLens\Analytics\Events\EventRegistry;
use BlueLens\Analytics\Events\EventValidator;
use BlueLens\Analytics\Events\EventWriter;
use BlueLens\Analytics\Events\FxConverter;
use BlueLens\Analytics\Modules\Ads\LocalAdsIntegration;
use BlueLens\Analytics\Modules\Ads\LocalAdsReport;
use BlueLens\Analytics\Modules\Audit\AuditController;
use BlueLens\Analytics\Modules\Audit\AuditReport;
use BlueLens\Analytics\Modules\Audit\PageAnalyzer;
use BlueLens\Analytics\Modules\Audit\SiteAudit;
use BlueLens\Analytics\Modules\Forms\FormIntegrations;
use BlueLens\Analytics\Privacy\DailySalt;
use BlueLens\Analytics\Privacy\PiiScrubber;
use BlueLens\Analytics\Privacy\VisitorHasher;
use BlueLens\Analytics\Tracking\BotDetector;
use BlueLens\Analytics\Tracking\ChannelClassifier;
use BlueLens\Analytics\Tracking\CollectController;
use BlueLens\Analytics\Tracking\Collector;
use BlueLens\Analytics\Tracking\CrawlerLogger;
use BlueLens\Analytics\Tracking\Geo\GeoDatabase;
use BlueLens\Analytics\Tracking\Geo\GeoLocator;
use BlueLens\Analytics\Tracking\HeatmapRecorder;
use BlueLens\Analytics\Tracking\PageContext;
use BlueLens\Analytics\Tracking\RateLimiter;
use BlueLens\Analytics\Tracking\SessionManager;
use BlueLens\Analytics\Tracking\TrackerLoader;
use BlueLens\Analytics\Tracking\UrlSanitizer;
use BlueLens\Analytics\Tracking\UserAgentParser;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton entry point for Blue Lens Analytics.
 */
final class Plugin {

	/**
	 * Action Scheduler group used for every background job this plugin schedules.
	 */
	public const ACTION_GROUP = 'blue-lens';

	/**
	 * Shared instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Whether hooks have been registered.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Builds the container. Services are constructed lazily on first use.
	 */
	private function __construct() {
		$this->container = new Container();
		$this->register_services();
	}

	/**
	 * Returns the shared plugin instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Returns the service container.
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Registers hooks for all services. Safe to call more than once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		foreach ( $this->hookable_services() as $id ) {
			$this->container->get( $id )->register_hooks();
		}

		add_action( 'init', [ $this, 'load_textdomain' ] );

		/**
		 * Fires once Blue Lens Analytics has registered its hooks.
		 *
		 * @param Plugin $plugin Plugin instance.
		 */
		do_action( 'blue_lens_loaded', $this );
	}

	/**
	 * Loads bundled translations.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'blue-lens-analytics', false, dirname( plugin_basename( BLA_FILE ) ) . '/languages' );
	}

	/**
	 * Registers service factories.
	 */
	private function register_services(): void {
		$c = $this->container;

		$c->set( Settings::class, static fn(): Settings => new Settings() );
		$c->set( Migrator::class, static fn(): Migrator => new Migrator() );
		$c->set( ConfigStore::class, static fn(): ConfigStore => new ConfigStore() );
		$c->set(
			Installer::class,
			static fn( Container $c ): Installer => new Installer( $c->get( Migrator::class ), $c->get( Settings::class ) )
		);
		$c->set(
			SettingsController::class,
			static fn( Container $c ): SettingsController => new SettingsController( $c->get( Settings::class ) )
		);
		$c->set(
			Menu::class,
			static fn( Container $c ): Menu => new Menu( $c->get( Settings::class ), $c->get( Migrator::class ), $c->get( GeoDatabase::class ) )
		);
		$c->set(
			Dashboard::class,
			static fn( Container $c ): Dashboard => new Dashboard( $c->get( Settings::class ), $c->get( Reports::class ), $c->get( Menu::class ), $c->get( LocalAdsReport::class ), $c->get( AuditReport::class ) )
		);

		// Aggregation and reports.
		$c->set( Aggregator::class, static fn(): Aggregator => new Aggregator() );
		$c->set( Retention::class, static fn( Container $c ): Retention => new Retention( $c->get( Settings::class ) ) );
		$c->set( Reports::class, static fn(): Reports => new Reports() );
		$c->set(
			ReportsController::class,
			static fn( Container $c ): ReportsController => new ReportsController( $c->get( Reports::class ), $c->get( Aggregator::class ), $c->get( LocalAdsReport::class ) )
		);
		$c->set( LocalAdsReport::class, static fn(): LocalAdsReport => new LocalAdsReport() );
		$c->set( LocalAdsIntegration::class, static fn(): LocalAdsIntegration => new LocalAdsIntegration() );

		// Site audit.
		$c->set( PageAnalyzer::class, static fn(): PageAnalyzer => new PageAnalyzer() );
		$c->set( SiteAudit::class, static fn( Container $c ): SiteAudit => new SiteAudit( $c->get( Settings::class ), $c->get( PageAnalyzer::class ) ) );
		$c->set( AuditReport::class, static fn( Container $c ): AuditReport => new AuditReport( $c->get( SiteAudit::class ) ) );
		$c->set( AuditController::class, static fn( Container $c ): AuditController => new AuditController( $c->get( SiteAudit::class ), $c->get( AuditReport::class ) ) );
		$c->set( Jobs::class, static fn(): Jobs => new Jobs() );

		// Privacy.
		$c->set( PiiScrubber::class, static fn(): PiiScrubber => new PiiScrubber() );
		$c->set( DailySalt::class, static fn(): DailySalt => new DailySalt() );
		$c->set( VisitorHasher::class, static fn( Container $c ): VisitorHasher => new VisitorHasher( $c->get( DailySalt::class ) ) );

		// Events.
		$c->set( EventRegistry::class, static fn(): EventRegistry => new EventRegistry() );
		$c->set( EventWriter::class, static fn(): EventWriter => new EventWriter() );
		$c->set( FxConverter::class, static fn( Container $c ): FxConverter => new FxConverter( $c->get( Settings::class ) ) );
		$c->set(
			EventValidator::class,
			static fn( Container $c ): EventValidator => new EventValidator( $c->get( EventRegistry::class ), $c->get( PiiScrubber::class ), $c->get( Settings::class ) )
		);

		// Tracking.
		$c->set( UrlSanitizer::class, static fn( Container $c ): UrlSanitizer => new UrlSanitizer( $c->get( PiiScrubber::class ) ) );
		$c->set( ChannelClassifier::class, static fn( Container $c ): ChannelClassifier => new ChannelClassifier( $c->get( ConfigStore::class ) ) );
		$c->set( UserAgentParser::class, static fn(): UserAgentParser => new UserAgentParser() );
		$c->set( BotDetector::class, static fn(): BotDetector => new BotDetector() );
		$c->set( GeoDatabase::class, static fn( Container $c ): GeoDatabase => new GeoDatabase( $c->get( Settings::class ) ) );
		$c->set( GeoLocator::class, static fn( Container $c ): GeoLocator => new GeoLocator( $c->get( GeoDatabase::class ) ) );
		$c->set( RateLimiter::class, static fn(): RateLimiter => new RateLimiter() );
		$c->set( SessionManager::class, static fn(): SessionManager => new SessionManager() );
		$c->set(
			Collector::class,
			static fn( Container $c ): Collector => new Collector(
				$c->get( Settings::class ),
				$c->get( VisitorHasher::class ),
				$c->get( SessionManager::class ),
				$c->get( EventValidator::class ),
				$c->get( EventWriter::class ),
				$c->get( UrlSanitizer::class ),
				$c->get( ChannelClassifier::class ),
				$c->get( UserAgentParser::class ),
				$c->get( BotDetector::class ),
				$c->get( GeoLocator::class ),
				$c->get( FxConverter::class ),
				$c->get( RateLimiter::class ),
				$c->get( PiiScrubber::class ),
				$c->get( HeatmapRecorder::class )
			)
		);
		$c->set( HeatmapRecorder::class, static fn(): HeatmapRecorder => new HeatmapRecorder() );
		$c->set(
			FormIntegrations::class,
			static fn( Container $c ): FormIntegrations => new FormIntegrations( $c->get( Collector::class ), $c->get( Settings::class ) )
		);
		$c->set(
			CollectController::class,
			static fn( Container $c ): CollectController => new CollectController( $c->get( Collector::class ), $c->get( Settings::class ) )
		);
		$c->set( PageContext::class, static fn(): PageContext => new PageContext() );
		$c->set(
			TrackerLoader::class,
			static fn( Container $c ): TrackerLoader => new TrackerLoader( $c->get( Settings::class ), $c->get( PageContext::class ) )
		);
		$c->set(
			CrawlerLogger::class,
			static fn( Container $c ): CrawlerLogger => new CrawlerLogger( $c->get( Settings::class ), $c->get( BotDetector::class ), $c->get( UrlSanitizer::class ) )
		);

		/**
		 * Lets extensions register or replace services before the plugin boots.
		 *
		 * @param Container $container Service container.
		 */
		do_action( 'blue_lens_register_services', $c );
	}

	/**
	 * Services whose hooks are registered on boot.
	 *
	 * @return list<class-string<Hookable>>
	 */
	private function hookable_services(): array {
		$services = [
			Settings::class,
			Installer::class,
			Jobs::class,
			SettingsController::class,
			GeoDatabase::class,
			CollectController::class,
			// Form plugins submit through admin-ajax/REST as well as front-end posts.
			FormIntegrations::class,
			LocalAdsIntegration::class,
			Aggregator::class,
			Retention::class,
			ReportsController::class,
			SiteAudit::class,
			AuditController::class,
		];

		if ( is_admin() ) {
			$services[] = Menu::class;
			$services[] = Dashboard::class;
		} else {
			$services[] = TrackerLoader::class;
			$services[] = CrawlerLogger::class;
		}

		return $services;
	}
}
