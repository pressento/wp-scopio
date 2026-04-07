<?php
/**
 * Sample PHPUnit test case.
 *
 * Rename this file and class for your own test, following the convention:
 *   test-{feature}.php → class Test_{Feature} extends WP_UnitTestCase
 */
class Test_Plugin_Name extends WP_UnitTestCase {

	/**
	 * Verify the plugin is loaded and version constant is defined.
	 */
	public function test_plugin_is_loaded(): void {
		$this->assertTrue( defined( 'PLUGIN_NAME_VERSION' ) );
	}
}
