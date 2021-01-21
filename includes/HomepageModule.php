<?php

namespace GrowthExperiments;

interface HomepageModule {

	public const RENDER_DESKTOP = 'desktop';
	public const RENDER_MOBILE_SUMMARY = 'mobile-summary';
	public const RENDER_MOBILE_DETAILS = 'mobile-details';
	public const RENDER_MOBILE_DETAILS_OVERLAY = 'mobile-overlay';

	/**
	 * Render the module in the given mode.
	 *
	 * @param string $mode One of RENDER_DESKTOP, RENDER_MOBILE_SUMMARY, RENDER_MOBILE_DETAILS
	 * @return string Html rendering of the module
	 */
	public function render( $mode );

	/**
	 * Get an array of data needed by the Javascript code related to this module.
	 * The data will be available in the 'homepagemodules' JS configuration field, keyed by module name.
	 * Keys currently in use:
	 * - html: module HTML
	 * - overlay: mobile overlay HTML
	 * - rlModules: ResourceLoader modules this module depends on
	 * - heading: module header text
	 * 'html' is only present when the module supports dynamic loading, 'overlay' and 'heading'
	 * in mobile summary/overlay mode, and 'rlModules' in both cases.
	 *
	 * @param string $mode One of RENDER_DESKTOP, RENDER_MOBILE_SUMMARY, RENDER_MOBILE_DETAILS
	 * @return array
	 */
	public function getJsData( $mode );

	/**
	 * Whether this module supports the given mode. If this returns false, render() and
	 * getJsData() should not be called with this mode.
	 * @param string $mode One of RENDER_DESKTOP, RENDER_MOBILE_SUMMARY, RENDER_MOBILE_DETAILS
	 * @return bool
	 */
	public function supports( $mode );
}
