<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class LGD_Plugin {
	private static $instance;
	private $booted = false;

	public static function instance() {
		if ( null === self::$instance ) { self::$instance = new self(); }
		return self::$instance;
	}

	private static function files() {
		return array(
			'includes/class-lgd-database.php', 'includes/class-lgd-logger.php',
			'includes/class-lgd-security.php', 'includes/class-lgd-post-types.php',
			'includes/contracts/interface-lgd-provider.php',
			'includes/providers/class-lgd-provider-registry.php',
			'includes/providers/class-lgd-provider-manual.php',
			'includes/providers/class-lgd-provider-steam.php',
			'includes/providers/class-lgd-provider-apple.php',
			'includes/providers/class-lgd-provider-google-play.php',
			'includes/providers/class-lgd-provider-itch.php',
			'includes/providers/class-lgd-provider-external-score.php',
			'includes/providers/class-lgd-provider-official-site.php',
			'includes/class-lgd-rating-engine.php', 'includes/class-lgd-ai-adapter.php',
			'includes/class-lgd-artwork-fetcher.php',
			'includes/class-lgd-importer.php', 'includes/class-lgd-scheduler.php',
			'includes/class-lgd-reviews.php', 'includes/class-lgd-comparison.php',
			'includes/class-lgd-engagement.php', 'includes/class-lgd-seo.php',
			'includes/class-lgd-admin.php', 'includes/class-lgd-frontend.php',
		);
	}

	private static function load_files() {
		foreach ( self::files() as $file ) { require_once LGD_PATH . $file; }
	}

	public function boot() {
		if ( $this->booted ) { return; }
		$this->booted = true;
		self::load_files();
		new LGD_Post_Types();
		new LGD_Provider_Registry();
		new LGD_Scheduler();
		new LGD_Reviews();
		new LGD_Comparison();
		new LGD_Engagement();
		new LGD_SEO();
		new LGD_Admin();
		new LGD_Frontend();
		add_action( 'admin_init', array( 'LGD_Database', 'maybe_upgrade' ) );
		add_action( 'init', array( 'LGD_AI_Adapter', 'ensure_provider_credentials' ), 6 );
	}

	public static function activate() {
		self::load_files();
		LGD_Database::install();
		LGD_Post_Types::register_all();
		LGD_Post_Types::seed_terms();
		LGD_Admin::add_capabilities();
		LGD_Scheduler::schedule_all();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		self::load_files();
		LGD_Scheduler::unschedule_all();
		flush_rewrite_rules();
	}
}
