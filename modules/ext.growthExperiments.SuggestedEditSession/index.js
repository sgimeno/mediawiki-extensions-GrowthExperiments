( function () {
	var Utils = require( '../utils/Utils.js' );
	var states = {
		/** Initial task state when the user opens a task. */
		STARTED: 'started',
		/** Task state after the user makes a real edit. */
		SAVED: 'saved',
		/** Task state after the user submits a structured task without making an edit. */
		SUBMITTED: 'submitted',
		/** Task state after the user leaves the workflow without saving or submitting anything. */
		CANCELLED: 'cancelled'
	};
	var allStates = [ states.STARTED, states.SAVED, states.SUBMITTED, states.CANCELLED ];

	/**
	 * Class for tracking suggested edit sessions and triggering actions related to them.
	 *
	 * A suggested edit session starts with a user clicking on a suggested edit task card,
	 * and ends with leaving the page associated with the task. It is tied to a single browser tab
	 * (but can be initiated by opening the task card in link in a new tab). During the session,
	 * the help panel switches to guidance mode; this class identifies the session, stores
	 * information needed for guidance, provides information for logging (some of it via its
	 * methods, some by adding tracking to URLs on setup), and triggers opening the post-edit
	 * dialog.
	 *
	 * See also HomepageHooks::onBeforePageDisplay().
	 *
	 * @class mw.libs.ge.SuggestedEditSession
	 * @constructor
	 * @private Use SuggestedEditSession.getInstance()
	 */
	function SuggestedEditSession() {
		OO.EventEmitter.call( this );

		// These variables are persisted for the lifetime of the session.

		/** @member {boolean} Whether we are in a suggested edit session currently. */
		this.active = false;
		/**
		 * @member {number|null} Suggested edit session ID. This will be used in
		 *   EditAttemptStep.editing_session_id and HelpPanel.help_panel_session_id
		 *   in events logged during the session. It is set via the geclickid URL parameter
		 *   (which is how a suggested edit session starts) and reset during page save.
		 */
		this.clickId = null;
		/** @member {mw.Title|null} The target page of the suggested editing task. */
		this.title = null;
		/** @member {string|null} Task type ID of the suggested editing task. */
		this.taskType = null;
		/**
		 * @member {Object|null} Tasktype-specific task data. Not all task types have this.
		 *   An 'error' field being present means that loading the task data failed (the
		 *   field will contain an error message); callers are expected to handle this gracefully.
		 */
		this.taskData = null;
		/** @member {string} Task state; one of the STATES constants. */
		this.taskState = null;
		/** @member {string|null} The editor used last in the suggested edit session. */
		this.editorInterface = null;
		/**
		 * @member {boolean} Show the post-edit dialog at the next opportunity. This is used to
		 *   work around page reloads after saving the page, when the dialog cannot be displayed
		 *   immediately when we detect the save.
		 */
		this.postEditDialogNeedsToBeShown = false;
		/** @member {boolean} Whether the mobile peek was already shown in this session. */
		this.mobilePeekShown = false;
		/** @member {boolean} Whether the help panel should be locked to its current panel. */
		this.helpPanelShouldBeLocked = false;
		/** @member {string|null} The current panel shown in the help panel. */
		this.helpPanelCurrentPanel = null;
		/** @member {boolean} Whether the help panel should open on page load. */
		this.helpPanelShouldOpen = true;
		/** @member {string|null} The current help panel tip visible. */
		this.helpPanelCurrentTip = null;
		/**
		 * @member {boolean} Whether the user interacted with the help panel in
		 * the suggested edits screen by navigating or clicking tips.
		 */
		this.helpPanelSuggestedEditsInteractionHappened = false;
		/** @member {boolean} Whether the article should be opened in edit mode after loading. */
		this.shouldOpenArticleInEditMode = false;
		/**
		 * @member {boolean} Whether onboarding dialog needs to be shown for the session
		 * This prevents onboading dialog from being shown multiple times during the same session.
		 * This is separate from user preference to dismiss onboarding altogether.
		 */
		this.onboardingNeedsToBeShown = true;
		/** @member {string|null} The newcomer task token set from NewcomerTaskLogger#log. */
		this.newcomerTaskToken = null;

		/**
		 * @member {Object} Persist data related to quality gates as the user interacts with the
		 * suggested edit. Used currently for keeping track of the daily limit gate for image
		 * recommendation on Minerva.
		 */
		this.qualityGateConfig = {};

		// These variables are not persisted and only used for immediate state management.

		/** @member {boolean} Flag to prevent double-opening of the post-edit dialog (T283120) */
		this.postEditDialogIsOpen = false;
		/**
		 * @member {number|undefined} Timestamp when the suggested edit session starts.
		 * Used for tracking load times for newcomer tasks. See HomepageHooks::onBeforePageDisplay.
		 */
		this.startTime = mw.config.get( 'suggestedEditSessionStartTime' );
	}
	OO.mixinClass( SuggestedEditSession, OO.EventEmitter );

	/** pseudo-constants for the 'taskState' property */
	SuggestedEditSession.static.STATES = states;

	/**
	 * Initialize the suggested edit session. This should be called on every page load.
	 * Depending on the situation, it might start a new session, load an existing one,
	 * terminate an existing one, or do nothing.
	 */
	SuggestedEditSession.prototype.initialize = function () {
		if ( this.maybeStart() ) {
			// We are right after the user clicking through a task card. Ignore all existing state.
			this.save();
		} else {
			this.maybeRestore();
		}

		if ( this.active ) {
			this.suppressNotices();
			this.updateEditorInterface();
			this.updateEditLinkUrls();
			this.maybeShowPostEditDialog();
		}
	};

	/**
	 * @return {mw.Title}
	 */
	SuggestedEditSession.prototype.getCurrentTitle = function () {
		var pageName = mw.config.get( 'wgPageName' );
		return new mw.Title( pageName );
	};

	/**
	 * Save the session to sessionStorage (meaning it only lives as long as the current
	 * browser tab) and also cache it in the current execution context.
	 */
	SuggestedEditSession.prototype.save = function () {
		var session = {
			clickId: this.clickId,
			title: this.title.getPrefixedText(),
			taskType: this.taskType,
			taskData: this.taskData,
			taskState: this.taskState,
			editorInterface: this.editorInterface,
			postEditDialogNeedsToBeShown: this.postEditDialogNeedsToBeShown,
			mobilePeekShown: this.mobilePeekShown,
			helpPanelShouldBeLocked: this.helpPanelShouldBeLocked,
			helpPanelCurrentPanel: this.helpPanelCurrentPanel,
			helpPanelShouldOpen: this.helpPanelShouldOpen,
			helpPanelCurrentTip: this.helpPanelCurrentTip,
			helpPanelSuggestedEditsInteractionHappened:
			this.helpPanelSuggestedEditsInteractionHappened,
			onboardingNeedsToBeShown: this.onboardingNeedsToBeShown,
			newcomerTaskToken: this.newcomerTaskToken,
			shouldOpenArticleInEditMode: this.shouldOpenArticleInEditMode,
			qualityGateConfig: this.qualityGateConfig
		};
		if ( !this.active ) {
			throw new Error( 'Trying to save an inactive suggested edit session' );
		}
		mw.storage.session.setObject( 'ge-suggestededit-session', session );
		mw.config.set( 'ge-suggestededit-session', this );
		this.emit( 'save', this );
	};

	/**
	 * Restore the stored suggested edit session into the current object. If it does not
	 * match the current request, terminate the session.
	 *
	 * @return {boolean} Whether an active session was successfully restored.
	 */
	SuggestedEditSession.prototype.maybeRestore = function () {
		var currentTitle, savedTitle,
			data = mw.storage.session.getObject( 'ge-suggestededit-session' );

		if ( this.active ) {
			throw new Error( 'Trying to load an already started suggested edit session' );
		}

		if ( data ) {
			try {
				currentTitle = this.getCurrentTitle();
				savedTitle = new mw.Title( data.title );
			} catch ( e ) {
				// handled at the end of the block
			}
			if ( currentTitle && savedTitle &&
				currentTitle.getSubjectPage().getPrefixedText() === savedTitle.getPrefixedText()
			) {
				this.active = true;
				this.clickId = data.clickId;
				this.title = savedTitle;
				this.taskType = data.taskType;
				this.taskData = data.taskData;
				this.taskState = data.taskState;
				this.editorInterface = data.editorInterface;
				this.postEditDialogNeedsToBeShown = data.postEditDialogNeedsToBeShown;
				this.mobilePeekShown = data.mobilePeekShown;
				this.helpPanelShouldBeLocked = data.helpPanelShouldBeLocked;
				this.helpPanelCurrentPanel = data.helpPanelCurrentPanel;
				this.helpPanelShouldOpen = data.helpPanelShouldOpen;
				this.helpPanelCurrentTip = data.helpPanelCurrentTip;
				this.helpPanelSuggestedEditsInteractionHappened =
					data.helpPanelSuggestedEditsInteractionHappened;
				this.onboardingNeedsToBeShown = data.onboardingNeedsToBeShown;
				this.newcomerTaskToken = data.newcomerTaskToken;
				this.shouldOpenArticleInEditMode = data.shouldOpenArticleInEditMode;
				this.qualityGateConfig = data.qualityGateConfig;
			} else {
				mw.storage.session.remove( 'ge-suggestededit-session' );
			}
		}
		return this.active;
	};

	/**
	 * See if the user has just started a suggested edit session (which is identified by a
	 * URL parameter).
	 *
	 * @return {boolean} Whether the session has been initiated.
	 */
	SuggestedEditSession.prototype.maybeStart = function () {
		var url = new mw.Uri();

		if ( this.active ) {
			throw new Error( 'Trying to start an already started active edit session' );
		}

		if ( url.query.geclickid ) {
			this.active = true;
			this.clickId = url.query.geclickid;
			this.title = this.getCurrentTitle();
			this.taskType = mw.config.get( 'wgGESuggestedEditTaskType' );
			this.taskData = mw.config.get( 'wgGESuggestedEditData' );
			this.qualityGateConfig = mw.config.get( 'wgGESuggestedEditQualityGateConfig' ) || {};
			this.taskState = states.STARTED;

			Utils.removeQueryParam( url, 'geclickid' );
			if ( url.query.getasktype ) {
				Utils.removeQueryParam( url, 'getasktype' );
			}
		}

		if ( url.query.genewcomertasktoken ) {
			this.newcomerTaskToken = url.query.genewcomertasktoken;
			Utils.removeQueryParam( url, 'genewcomertasktoken' );
		}

		// url.query.gesuggestededit is not removed from the URL, because we need it to survive
		// page reloads for any code that depends on its presence (e.g. loading the help panel
		// when the preference is switched off T284088)

		// Don't show help panel & mobile peek if the article is in edit mode
		this.shouldOpenArticleInEditMode = url.query.veaction === 'edit';
		this.helpPanelShouldOpen = !this.shouldOpenArticleInEditMode;
		this.mobilePeekShown = this.shouldOpenArticleInEditMode;

		return this.active;
	};

	SuggestedEditSession.prototype.suppressNotices = function () {
		var veState = mw.loader.getState( 'ext.visualEditor.desktopArticleTarget.init' );

		// Prevent the default post-edit notice. This would logically belong to the
		// PostEdit module, but that would load too late.
		mw.config.set( 'wgPostEditConfirmationDisabled', true );
		// Suppress the VisualEditor welcome dialog and education popups
		// Do this only if VE's init module was already going to be loaded; we don't want to trigger
		// it if it wasn't going to be loaded otherwise
		if ( veState === 'loading' || veState === 'loaded' || veState === 'ready' ) {
			mw.loader.using( 'ext.visualEditor.desktopArticleTarget.init' ).done( function () {
				mw.libs.ve.disableWelcomeDialog();
				mw.libs.ve.disableEducationPopups();
			} );
		}
	};

	SuggestedEditSession.prototype.setTaskState = function ( state ) {
		if ( allStates.indexOf( state ) !== -1 ) {
			this.taskState = state;
			this.save();
		} else {
			mw.log.error( 'SuggestedEditSession.setTaskState: invalid state ' + state );
			mw.errorLogger.logError( new Error( 'SuggestedEditSession.setTaskState: invalid state ' + state ), 'error.growthexperiments' );
		}
	};

	SuggestedEditSession.prototype.updateEditorInterface = function () {
		var self = this,
			saveEditorChanges = function ( suggestedEditSession, editorInterface ) {
				if ( suggestedEditSession.active &&
					suggestedEditSession.editorInterface !== editorInterface &&
					Utils.isValidEditor( editorInterface )
				) {
					suggestedEditSession.editorInterface = editorInterface;
					suggestedEditSession.save();
				}
			};

		mw.trackSubscribe( 'event.EditAttemptStep', function ( _, data ) {
			saveEditorChanges( self, data.editor_interface );
		} );
		// MobileFrontend has its own schema wrapper
		mw.trackSubscribe( 'mf.schemaEditAttemptStep', function ( _, data ) {
			saveEditorChanges( self, data.editor_interface );
		} );
		// WikiEditor doesn't use mw.track. But it doesn't load dynamically either so
		// we can check it at page load time.
		$( function () {
			var uri = new mw.Uri();

			// eslint-disable-next-line no-jquery/no-global-selector, no-jquery/no-sizzle
			if ( uri.query.action === 'edit' && $( '#wpTextbox1:visible' ).length ) {
				saveEditorChanges( self, 'wikitext' );
			}
		} );
	};

	/**
	 * Change the URL of edit links to propagate the editing session ID to certain log records,
	 * as well as the 'gesuggestededit' parameter that indicates the user is doing a suggested edit.
	 */
	SuggestedEditSession.prototype.updateEditLinkUrls = function () {
		var self = this,
			linkSelector = '#ca-edit a[href], a#ca-edit[href], #ca-ve-edit a[href], ' +
				'a#ca-ve-edit[href], .mw-editsection a[href]';

		mw.config.set( 'wgWMESchemaEditAttemptStepSamplingRate', 1 );
		$( function () {
			$( linkSelector ).each( function () {
				var linkUrl = new mw.Uri( this.href );
				linkUrl.extend( {
					editingStatsId: self.clickId,
					editingStatsOversample: 1,
					gesuggestededit: 1
				} );
				$( this ).attr( 'href', linkUrl.toString() );
			} );
		} );
	};

	/**
	 * Display the post-edit dialog, and deal with some editors reloading the page immediately
	 * after save.
	 *
	 * @param {Object} config
	 * @param {boolean} [config.resetSession] Reset the session ID. This should be done when the
	 *   dialog is displayed, but it should not be done twice if this method is called twice
	 *   due to a reload.
	 * @param {boolean} [config.nextRequest] Don't try to display the dialog, schedule it for the
	 *   next request instead. This is less fragile when we know for sure the editor will reload.
	 * @return {jQuery.Promise} A promise that resolves when the dialog is displayed.
	 */
	SuggestedEditSession.prototype.showPostEditDialog = function ( config ) {
		var self = this,
			uri = new mw.Uri();

		// T283120: avoid opening the dialog multiple times at once. This shouldn't be
		// happening but with the various delayed mechanisms for opening the dialog, it's
		// hard to avoid.
		if ( this.postEditDialogIsOpen ) {
			return $.Deferred().resolve().promise();
		}

		if ( config.resetSession ) {
			self.clickId = mw.user.generateRandomSessionId();
			// Need to update the click ID in edit links as well.
			self.updateEditLinkUrls();
		}

		// The mobile editor and in some configurations the visual editor immediately reloads
		// after saving and firing the post-edit event, so displaying the dialog would fail.
		// Preventing that reload would be fragile, given that the post-edit dialog offers
		// users an "edit again" option. Instead, use the session to display the dialog again
		// after the reload if needed.
		this.postEditDialogNeedsToBeShown = true;
		this.save();

		if ( !config.nextRequest && mw.config.get( 'wgGENewcomerTasksGuidanceEnabled' ) &&
			// Never show the dialog on an edit screen, just defer it to the next request.
			// This can happen when VisualEditor fires the postEdit hook before reloading the page.
			!( uri.query.veaction || uri.query.action === 'edit' )
		) {
			this.postEditDialogIsOpen = true;
			return mw.loader.using( 'ext.growthExperiments.PostEdit' ).then( function ( require ) {
				return require( 'ext.growthExperiments.PostEdit' ).setupPanel().then( function ( result ) {
					result.openPromise.done( function () {
						self.postEditDialogNeedsToBeShown = false;
						self.save();
					} );
					result.closePromise.done( function () {
						self.postEditDialogIsOpen = false;
					} );
					return result.openPromise;
				} );
			} );
		}
		return $.Deferred().resolve().promise();
	};

	/**
	 * Display the post-edit dialog if we are in a suggested edit session, right after an edit.
	 */
	SuggestedEditSession.prototype.maybeShowPostEditDialog = function () {
		var self = this,
			currentTitle = this.getCurrentTitle(),
			hasSwitchedFromMachineSuggestions = new mw.Uri().query.hideMachineSuggestions;

		// Only show the post-edit dialog on the task page, not e.g. on talk page edits.
		// Skip the dialog if the user saved an edit w/VE after switching from suggestions mode.
		if ( !currentTitle || !this.title ||
			currentTitle.getPrefixedText() !== this.title.getPrefixedText() ||
			hasSwitchedFromMachineSuggestions
		) {
			return;
		}

		if ( this.postEditDialogNeedsToBeShown ) {
			this.showPostEditDialog( {} );
		}

		// Do this even if we have just shown the dialog above. This is important when the user
		// edits again right after dismissing the dialog.
		mw.hook( 'postEdit' ).add( function () {
			self.setTaskState( states.SAVED );
			self.showPostEditDialog( { resetSession: true } );
		} );
		/**
		 * @param {Object} data
		 * @param {number|null} [data.newRevId] ID of the newly created revision, or null if it was
		 *  a null edit.
		 */
		mw.hook( 'postEditMobile' ).add( function ( data ) {
			self.setTaskState( data.newRevId ? states.SAVED : states.SUBMITTED );
			self.showPostEditDialog( { resetSession: true, nextRequest: true } );
		} );
	};

	/**
	 * Track the duration for setting up the editing surface
	 *
	 * This is for structured tasks only (since the editor is opened automatically).
	 */
	SuggestedEditSession.prototype.trackEditorReady = function () {
		if ( !this.startTime ) {
			return;
		}
		mw.track(
			'timing.growthExperiments.suggestedEdits.taskEditorReady.' + this.taskType +
			( OO.ui.isMobile() ? '.mobile' : '.desktop' ),
			mw.now() - this.startTime
		);
	};

	/**
	 * Track the duration for setting up the guidance panel
	 *
	 * This is for unstructured tasks only.
	 * For mobile, this is when the mobile peek (first time with unstructured tasks) or the
	 * help panel button is shown. For desktop, this is when the help panel button is shown.
	 */
	SuggestedEditSession.prototype.trackGuidanceShown = function () {
		// If the editor is opened automatically, taskEditorReady should be used instead.
		if ( this.shouldOpenArticleInEditMode || !this.startTime ) {
			return;
		}
		mw.track(
			'timing.growthExperiments.suggestedEdits.guidanceShown.' + this.taskType +
			( OO.ui.isMobile() ? '.mobile' : '.desktop' ),
			mw.now() - this.startTime
		);
	};

	/**
	 * Get a suggested edit session. This is the entry point for other code using this class.
	 *
	 * @return {mw.libs.ge.SuggestedEditSession}
	 */
	SuggestedEditSession.getInstance = function () {
		var session = mw.config.get( 'ge-suggestededit-session' );

		if ( session ) {
			return session;
		}
		session = new SuggestedEditSession();
		session.initialize();
		mw.config.set( 'ge-suggestededit-session', session );
		return session;
	};

	// Always initiate. We need to do this to be able to terminate the session when the user
	// navigates away from the target page.
	SuggestedEditSession.getInstance();

	// To facilitate QA/debugging.
	window.ge = window.ge || {};
	ge.suggestedEditSession = SuggestedEditSession.getInstance();

	module.exports = SuggestedEditSession;
}() );
