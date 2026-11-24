<?php

namespace Outstand\WP\FluentForm\Addons\Privacy\Tests\Unit;

use Outstand\WP\FluentForm\Addons\Privacy\Plugin;
use Outstand\WP\FluentForm\Addons\Privacy\Retention;
use WP_UnitTestCase;

/**
 * Plugin bootstrap: singleton, module registration, and deactivation cleanup.
 *
 * @covers \Outstand\WP\FluentForm\Addons\Privacy\Plugin
 */
class PluginTest extends WP_UnitTestCase {

	/**
	 * Returns the same singleton every call.
	 *
	 * @return void
	 */
	public function test_get_instance_returns_singleton(): void {
		$this->assertSame( Plugin::get_instance(), Plugin::get_instance() );
	}

	/**
	 * Deactivation clears the scheduled prune cron.
	 *
	 * @return void
	 */
	public function test_deactivate_clears_the_prune_cron(): void {
		wp_schedule_event( time(), 'daily', Retention::CRON_HOOK );
		$this->assertNotFalse( wp_next_scheduled( Retention::CRON_HOOK ) );

		Plugin::deactivate();

		$this->assertFalse( wp_next_scheduled( Retention::CRON_HOOK ) );
	}

	/**
	 * Registers every module without throwing, regardless of which are eligible in this environment.
	 *
	 * @return void
	 */
	public function test_enable_registers_modules_without_error(): void {
		Plugin::get_instance()->enable();

		$this->assertTrue( true );
	}
}
