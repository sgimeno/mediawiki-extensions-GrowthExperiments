<?php

namespace GrowthExperiments;

use Exception;
use IContextSource;
use MediaWiki\Auth\AuthManager;
use MediaWiki\Logger\LoggerFactory;
use MWExceptionHandler;
use Sanitizer;
use Skin;
use SkinMinerva;
use Throwable;
use User;

class Util {

	const MINUTE = 60;
	const HOUR = 3600;
	const DAY = 86400;
	const WEEK = 604800;
	const MONTH = 2592000;
	const YEAR = 31536000;

	/**
	 * Helper method to check if a user can set their email.
	 *
	 * Called from the Help Panel and the Welcome Survey when a user has no email, or has
	 * an email that has not yet been confirmed.
	 *
	 * To check if a user with no email can set a particular email, pass in only the second
	 * argument; to check if a user with an unconfirmed email can set a particular email set the
	 * third argument to false.
	 *
	 * @param User $user
	 * @param null $newEmail
	 * @param bool $checkConfirmedEmail
	 * @return bool
	 */
	public static function canSetEmail( User $user, $newEmail = null, $checkConfirmedEmail = true ) {
		return ( $checkConfirmedEmail ?
				!$user->getEmail() || !$user->isEmailConfirmed() :
				!$user->getEmail() ) &&
			$user->isAllowed( 'viewmyprivateinfo' ) &&
			$user->isAllowed( 'editmyprivateinfo' ) &&
			AuthManager::singleton()->allowsPropertyChange( 'emailaddress' ) &&
			( $newEmail ? Sanitizer::validateEmail( $newEmail ) : true );
	}

	/**
	 * @param IContextSource $contextSource
	 * @param int $elapsedTime
	 * @return string
	 */
	public static function getRelativeTime( IContextSource $contextSource, $elapsedTime ) {
		return $contextSource->getLanguage()->formatDuration(
			$elapsedTime,
			self::getIntervals( $elapsedTime )
		);
	}

	/**
	 * Return the intervals passed as second arg to Language->formatDuration().
	 * @param int $time
	 *  Elapsed time since account creation in seconds.
	 * @return array
	 */
	private static function getIntervals( $time ) {
		if ( $time < self::MINUTE ) {
			return [ 'seconds' ];
		} elseif ( $time < self::HOUR ) {
			return [ 'minutes' ];
		} elseif ( $time < self::DAY ) {
			return [ 'hours' ];
		} elseif ( $time < self::WEEK ) {
			return [ 'days' ];
		} elseif ( $time < self::MONTH ) {
			return [ 'weeks' ];
		} elseif ( $time < self::YEAR ) {
			return [ 'weeks' ];
		} else {
			return [ 'years', 'weeks' ];
		}
	}

	/**
	 * @param Skin $skin
	 * @return bool Whether the given skin is considered "mobile".
	 */
	public static function isMobile( Skin $skin ) {
		return $skin instanceof SkinMinerva;
	}

	/**
	 * Add the guided tour module if the user is logged-in, hasn't seen the tour already,
	 * and the tour dependencies are loaded.
	 *
	 * @param \OutputPage $out
	 * @param string $pref
	 * @param string|string[] $modules
	 */
	public static function maybeAddGuidedTour( \OutputPage $out, $pref, $modules ) {
		if ( $out->getUser()->isLoggedIn() &&
			!$out->getUser()->getBoolOption( $pref ) &&
			TourHooks::growthTourDependenciesLoaded() ) {
			$out->addModules( $modules );
		}
	}

	/**
	 * Log an error. Configuration errors are logged to the GrowthExperiments channel,
	 * internal errors are logged to the exception channel.
	 * @param Exception|Throwable $error Error object from the catch block (Exception
	 *   in PHP5/HHVM, Throwable in PHP7)
	 * @param array $extraData
	 */
	public static function logError( $error, array $extraData = [] ) {
		if ( $error instanceof WikiConfigException ) {
			LoggerFactory::getInstance( 'GrowthExperiments' )->error(
				$error->getMessage(), $extraData + [ 'exception' => $error ] );
		} else {
			MWExceptionHandler::logException( $error, MWExceptionHandler::CAUGHT_BY_OTHER, $extraData );
		}
	}
}
