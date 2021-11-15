var StructuredTaskPreEdit = require( 'ext.growthExperiments.StructuredTask.PreEdit' ),
	suggestedEditSession = require( 'ext.growthExperiments.SuggestedEditSession' ).getInstance(),
	ADD_IMAGE_CAPTION_ONBOARDING_PREF = 'growthexperiments-addimage-caption-onboarding',
	MAX_IMAGE_DISPLAY_WIDTH = 500;

/**
 * @typedef mw.libs.ge.ImageRecommendationImage
 * @property {string} image Image filename in unprefixed DBkey format.
 * @property {string} source Recommendation source; one of 'wikidata', 'wikipedia', 'commons'.
 * @property {string[]} projects Lists of projects (as wiki IDs) the recommendation is from.
 *   Only used when the source is 'wikipedia'.
 * @property {Object} metadata See ImageRecommendationMetadataProvider::getMetadata()
 * @property {string} metadata.descriptionUrl File description page URL.
 * @property {string} metadata.fullUrl URL of full-sized image.
 * @property {string} metadata.thumbUrl URL of image thumbnail used in the toolbar dialog.
 * @property {number} metadata.originalWidth Width of original image in pixels.
 * @property {number} metadata.originalHeight Height of original image in pixels.
 * @property {boolean} metadata.mustRender True if the original image wouldn't display correctly
 *   in a browser.
 * @property {boolean} metadata.isVectorized Whether the image is a vector image (ie. has no max size).
 * @property {string|null} metadata.description Image description (sanitized HTML).
 * @property {string|null} metadata.author Original author of image (sanitized HTML).
 * @property {string|null} metadata.license Short license name (sanitized HTML).
 * @property {string} metadata.date Date of original image creation (sanitized HTML).
 * @property {string|null} metadata.caption MediaInfo caption (plain text).
 * @property {string[]} metadata.categories Non-hidden categories of the image, in
 *   mw.Title.getMainText() format.
 * @property {string} metadata.reason Description of why the image is being recommended (plain text).
 * @property {string} metadata.contentLanguageName Name of the content language (plain text).
 */

/**
 * @typedef mw.libs.ge.ImageRecommendation
 * @property {mw.libs.ge.ImageRecommendationImage[]} images Recommended images.
 * @property {string} datasetId Dataset version ID.
 */

/**
 * @typedef mw.libs.ge.ImageRecommendationSummary
 * @property {boolean} accepted Whether the image was accepted
 * @property {string} filename Image file name
 * @property {string} thumbUrl URL of the image thumbnail
 * @property {string} caption Entered value for the image caption
 *
 */

/**
 * Mixin for a ve.init.mw.ArticleTarget instance. Used by AddImageDesktopArticleTarget and
 * AddImageMobileArticleTarget.
 *
 * @mixin mw.libs.ge.AddImageArticleTarget
 * @extends ve.init.mw.ArticleTarget
 * @extends mw.libs.ge.StructuredTaskArticleTarget
 */
function AddImageArticleTarget() {
	/**
	 * @property {boolean|null} recommendationAccepted Recommendation acceptance status
	 *   (true: accepted; false: rejected; null: not decided yet).
	 */
	this.recommendationAccepted = null;

	/**
	 * @property {string[]} recommendationRejectionReasons List of rejection reason IDs.
	 */
	this.recommendationRejectionReasons = [];

	/**
	 * @property {number} selectedImageIndex Zero-based index of the selected image suggestion.
	 */
	this.selectedImageIndex = 0;
}

AddImageArticleTarget.prototype.beforeSurfaceReady = function () {
	/**
	 * @property {mw.libs.ge.ImageRecommendationImage[]} images
	 */
	this.images = suggestedEditSession.taskData.images;
};

/**
 * Show the image inspector, hide save button
 */
AddImageArticleTarget.prototype.afterSurfaceReady = function () {
	if ( this.isValidTask() ) {
		// Set a reference to the toolbar up front so that it's available in subsequent calls
		// (since VeUiTargetToolbar is constructed upon these method calls if it's not there)
		this.targetToolbar = OO.ui.isMobile() ? this.getToolbar() : this.getActions();
		// Save button will be shown during caption step
		this.toggleSaveTool( false );
		this.getSurface().executeCommand( 'recommendedImage' );
		// On desktop, onboarding is shown after the editor loads.
		if ( !OO.ui.isMobile() ) {
			mw.hook( 'growthExperiments.structuredTask.showOnboardingIfNeeded' ).fire();
		}
		this.logger.log( 'impression', {}, {
			// eslint-disable-next-line camelcase
			active_interface: 'machinesuggestions_mode'
		} );
	} else {
		// Ideally, this error would happen sooner so the user doesn't have to wait for VE
		// to load. There isn't really a way to differentiate between images in the article
		// and transcluded images without loading VE and loading the parsoid HTML, though.
		StructuredTaskPreEdit.showErrorDialogOnFailure();
	}
};

/** @inheritDoc **/
AddImageArticleTarget.prototype.hasEdits = function () {
	return this.recommendationAccepted === true;
};

/** @inheritDoc **/
AddImageArticleTarget.prototype.hasReviewedSuggestions = function () {
	return this.recommendationAccepted === true || this.recommendationAccepted === false;
};

/**
 * Check if the current article is a valid Add Image task (does not have any image yet).
 *
 * @return {boolean}
 */
AddImageArticleTarget.prototype.isValidTask = function () {
	var surfaceModel = this.getSurface().getModel();

	if ( surfaceModel.getDocument().getNodesByType( 'mwBlockImage' ).length ||
		surfaceModel.getDocument().getNodesByType( 'mwInlineImage' ).length
	) {
		return false;
	}
	// TODO check for images in infoboxes, once we support articles with infoboxes.
	return true;
};

/**
 * Add the recommended image to the VE document.
 *
 * @param {mw.libs.ge.ImageRecommendationImage} imageData
 */
AddImageArticleTarget.prototype.insertImage = function ( imageData ) {
	var linearModel, insertOffset, dimensions,
		surfaceModel = this.getSurface().getModel(),
		data = surfaceModel.getDocument().data,
		NS_FILE = mw.config.get( 'wgNamespaceIds' ).file,
		imageTitle = new mw.Title( imageData.image, NS_FILE ),
		thumb = mw.util.parseImageUrl( imageData.metadata.thumbUrl ),
		targetWidth, targetSrcWidth;

	// Define the image to be inserted.
	// This will eventually be passed as the data parameter to MWBlockImageNode.toDomElements.
	// See also https://www.mediawiki.org/wiki/Specs/HTML/2.2.0#Images

	dimensions = {
		width: imageData.metadata.originalWidth,
		height: imageData.metadata.originalHeight
	};
	targetWidth = Math.min(
		this.getSurface().getView().$documentNode.width(),
		MAX_IMAGE_DISPLAY_WIDTH
	);
	targetSrcWidth = Math.floor( targetWidth * window.devicePixelRatio );
	linearModel = [
		{
			type: 'mwGeRecommendedImage',
			attributes: {
				mediaClass: 'Image',
				// This is a Commons image so the link needs to use the English namespace name
				// but Title uses the localized one. That's OK, Parsoid will figure it out.
				// Native VE images also use localized titles.
				href: './' + imageTitle.getPrefixedText(),
				resource: './' + imageTitle.getPrefixedText(),
				type: 'thumb',
				defaultSize: true,
				// The generated wikitext will ignore width/height when defaultSize is set, but
				// it's still used for the visual size of the thumbnail in the editor, so set it
				// to the width of the article (with max width set to account for tablets).
				width: targetWidth,
				height: targetWidth * ( dimensions.height / dimensions.width ),
				// Likewise only used in the editor UI. Work around an annoying quirk of MediaWiki
				// where a thumbnail with the exact same size as the original is not always valid.
				src: thumb.resizeUrl ?
					thumb.resizeUrl( Math.min( targetSrcWidth,
						imageData.metadata.originalWidth - 1 ) ) :
					imageData.metadata.thumbUrl,
				align: 'default',
				filename: imageData.image,
				originalClasses: [ 'mw-default-size' ],
				isError: false,
				mw: {},
				// Pass image recommendation metadata to CERecommendedImageNode
				recommendation: imageData,
				recommendationIndex: this.selectedImageIndex
			},
			internal: {
				whitespace: [ '\n', undefined, undefined, '\n' ]
			}
		},
		{ type: 'mwGeRecommendedImageCaption' },
		{ type: 'paragraph', internal: { generated: 'wrapper' } },
		// Caption will be spliced in here. In the linear model each character is a separate item.
		{ type: '/paragraph' },
		{ type: '/mwGeRecommendedImageCaption' },
		{ type: '/mwGeRecommendedImage' }
	];

	// Find the position between the initial templates and text.
	insertOffset = data.getRelativeOffset( 0, 1, function ( offset ) {
		return this.isEndOfMetadata( data, offset );
	}.bind( this ) );
	if ( insertOffset === -1 ) {
		// No valid position found. This shouldn't be possible.
		mw.log.error( 'No valid offset found for image insertion' );
		mw.errorLogger.logError( new Error( 'No valid offset found for image insertion' ),
			'error.growthexperiments' );
		insertOffset = 0;
	}

	// Actually insert the image.
	surfaceModel.setReadOnly( false );
	surfaceModel.getLinearFragment( new ve.Range( insertOffset ) ).insertContent( linearModel );
	surfaceModel.setReadOnly( true );
	this.hasStartedCaption = true;
};

/**
 * Check whether a given offset in the linear model is the end of the leading metadata block
 * (in an editorial sense, not a technical sense).
 *
 * @param {ve.dm.ElementLinearData} data
 * @param {number} offset
 * @return {boolean}
 * @private
 */
AddImageArticleTarget.prototype.isEndOfMetadata = function ( data, offset ) {
	if ( !data.isContentOffset( offset ) ) {
		return false;
	}
	// we know this exists, otherwise isContentOffset would fail
	var right = data.getData( offset );

	// Special-case newlines because we don't want to stop at newlines separating templates.
	if ( right === '\n' ) {
		return this.isEndOfMetadata( data, offset + 1 );
	}
	// plain text or annotated text
	if ( typeof right === 'string' || Array.isArray( right ) ) {
		return true;
	}
	// right is an object. Skip it if it's a template or invisible metadata.
	if ( [
		// templates
		'mwTransclusion', 'mwTransclusionBlock', 'mwTransclusionInline', 'mwTransclusionTableCell',
		// ve.dm.MetaItem subclasses
		'mwAlienMeta', 'mwCategory', 'mwDefaultSort', 'mwDisplayTitle', 'mwHiddenCategory',
		'mwIndex', 'mwLanguage', 'mwNewSectionEdit', 'mwNoContentConvert', 'mwNoEditSection',
		'mwNoGallery', 'mwNoTitleConvert', 'mwDisambiguation',
		// hidden, so probably should go before the image
		'comment', 'mwLanguageVariantHidden'
	].indexOf( right.type ) !== -1 ) {
		return false;
	}
	return true;
};

/**
 * Undo the last change. Can be used to undo insertImage().
 */
AddImageArticleTarget.prototype.rollback = function () {
	var surfaceModel = this.getSurface().getModel(), recommendedImageNodes;
	surfaceModel.setReadOnly( false );
	recommendedImageNodes = surfaceModel.getDocument().getNodesByType( 'mwGeRecommendedImage' );
	recommendedImageNodes.forEach( function ( node ) {
		surfaceModel.getLinearFragment( node.getOuterRange() ).delete();
	} );
	surfaceModel.setReadOnly( true );
	this.updateSuggestionState( this.selectedImageIndex, undefined, [] );
};

/** @inheritDoc **/
AddImageArticleTarget.prototype.save = function ( doc, options, isRetry ) {
	options.plugins = 'ge-task-image-recommendation';
	// This data will be processed in HomepageHooks::onVisualEditorApiVisualEditorEditPostSaveHook
	options[ 'data-ge-task-image-recommendation' ] = JSON.stringify( {
		filename: this.getSelectedSuggestion().image,
		accepted: this.recommendationAccepted,
		reasons: this.recommendationRejectionReasons
	} );
	return this.constructor.super.prototype.save.call( this, doc, options, isRetry );
};

/**
 * Get the image and its acceptance data to be used in the save dialog
 *
 * @return {mw.libs.ge.ImageRecommendationSummary}
 */
AddImageArticleTarget.prototype.getSummaryData = function () {
	var imageData = this.getSelectedSuggestion(),
		surfaceModel = this.getSurface().getModel(),
		/** @type {mw.libs.ge.ImageRecommendationSummary} */
		summaryData = {
			filename: imageData.image,
			accepted: this.recommendationAccepted,
			thumbUrl: imageData.metadata.thumbUrl,
			caption: ''
		};
	if ( this.recommendationAccepted ) {
		var documentDataModel = surfaceModel.getDocument(),
			captionNode = documentDataModel.getNodesByType( 'mwGeRecommendedImageCaption' )[ 0 ],
			caption = surfaceModel.getLinearFragment( captionNode.getRange() ).getText();
		summaryData.caption = caption;
	}
	return summaryData;
};

/**
 * Get the save tool group
 *
 * @return {OO.ui.ToolGroup}
 */
AddImageArticleTarget.prototype.getSaveToolGroup = function () {
	return this.targetToolbar.getToolGroupByName( 'save' );
};

/**
 * Get the edit mode tool group
 *
 * @return {OO.ui.ToolGroup}
 */
AddImageArticleTarget.prototype.getEditModeToolGroup = function () {
	return this.targetToolbar.getToolGroupByName( 'suggestionsEditMode' );
};

/**
 * Toggle the visibility of the save tool
 *
 * @param {boolean} shouldShow Whether the save tool should be shown
 */
AddImageArticleTarget.prototype.toggleSaveTool = function ( shouldShow ) {
	var saveToolGroup = this.getSaveToolGroup();
	if ( saveToolGroup ) {
		saveToolGroup.toggle( shouldShow );
	}
};

/**
 * Set the disabled state of the save tool
 *
 * @param {boolean} shouldDisable Whether the save tool should be disabled
 */
AddImageArticleTarget.prototype.setDisabledSaveTool = function ( shouldDisable ) {
	var saveToolGroup = this.getSaveToolGroup();
	if ( saveToolGroup ) {
		saveToolGroup.setDisabled( shouldDisable );
	}
};

/**
 * Toggle the visibility of the edit mode tool
 *
 * @param {boolean} shouldShow Whether the edit mode tool should be shown
 */
AddImageArticleTarget.prototype.toggleEditModeTool = function ( shouldShow ) {
	var editModeToolGroup = this.getEditModeToolGroup();
	if ( editModeToolGroup ) {
		editModeToolGroup.toggle( shouldShow );
	}
};

/**
 * Show the caption info dialog.
 *
 * When the dialog is opened automatically (when the user accepts a suggestion and hasn't dismissed
 * the caption onboarding dialog), the user preference should be checked to determine if the user
 * has opted out of seeing the dialog. When the dialog is opened via a click on the help button
 * during caption step, the preference doesn't need to be checked.
 *
 * By default, the dialog is opened without checking the user's preference.
 *
 * @param {boolean} [shouldCheckPref] Whether the dialog preference should be taken into account
 */
AddImageArticleTarget.prototype.showCaptionInfoDialog = function ( shouldCheckPref ) {
	var dialogName = 'addImageCaptionInfo',
		logCaptionInfoDialog = function ( action, context, closeData ) {
			/* eslint-disable camelcase */
			var actionData = $.extend(
				this.getSuggestionLogActionData(),
				{ dialog_context: context }
			);
			if ( closeData ) {
				actionData.dont_show_again = closeData.dialogDismissed;
			}
			this.logger.log( action, actionData, { active_interface: 'captioninfo_dialog' } );
			/* eslint-enable camelcase */
		}.bind( this ),
		openDialogPromise;

	if ( !shouldCheckPref ) {
		openDialogPromise = this.surface.dialogs.openWindow( dialogName );
		openDialogPromise.opening.then( function () {
			logCaptionInfoDialog( 'impression', 'help' );
		} );
		openDialogPromise.closed.then( function () {
			logCaptionInfoDialog( 'close', 'help' );
		} );
		return;
	}

	// The dialog was shown already during the session (the user went back from caption step)
	if ( this.hasShownCaptionOnboarding ) {
		return;
	}

	if ( !mw.user.options.get( ADD_IMAGE_CAPTION_ONBOARDING_PREF ) ) {
		this.hasShownCaptionOnboarding = true;
		openDialogPromise = this.surface.dialogs.openWindow(
			dialogName,
			{ shouldShowDismissField: true }
		);
		openDialogPromise.opening.then( function () {
			logCaptionInfoDialog( 'impression', 'onboarding' );
		} );
		openDialogPromise.closed.then( function ( closeData ) {
			logCaptionInfoDialog( 'close', 'onboarding', closeData );
		} );
	}
};

/**
 * Get data for the suggestion at the specified index (if any) or the selected suggestion
 * to pass to ImageSuggestionInteractionLogger.
 *
 * @param {number} [index] Zero-based index of the image suggestion for which to return data
 * @return {Object}
 */
AddImageArticleTarget.prototype.getSuggestionLogActionData = function ( index ) {
	var imageIndex = typeof index === 'number' ? index : this.selectedImageIndex,
		imageData = this.images[ imageIndex ],
		isUndecided = typeof this.recommendationAccepted !== 'boolean',
		acceptanceState = this.recommendationAccepted ? 'accepted' : 'rejected';
	return {
		/* eslint-disable camelcase */
		filename: imageData.image,
		recommendation_source: imageData.source,
		recommendation_source_projects: imageData.projects,
		series_number: imageIndex + 1,
		total_suggestions: this.images.length,
		rejection_reasons: this.recommendationRejectionReasons,
		acceptance_state: isUndecided ? 'undecided' : acceptanceState
		/* eslint-enable camelcase */
	};
};

/**
 * Log actions specific to the current suggestion
 *
 * @param {string} action Name of the action the user took
 * @param {string} activeInterface Name of the current interface
 * @param {Object} [actionData] Additional action data
 */
AddImageArticleTarget.prototype.logSuggestionInteraction = function (
	action, activeInterface, actionData ) {
	this.logger.log(
		action,
		$.extend( actionData || {}, this.getSuggestionLogActionData() ),
		// eslint-disable-next-line camelcase
		{ active_interface: activeInterface }
	);
};

/**
 * Update the state of the image suggestion at the specified index
 *
 * @param {number} index Zero-based index of the image suggestion being updated
 * @param {boolean|undefined} accepted Whether the image suggestion is accepted;
 *  Undefined indicates that the user hasn't decided.
 * @param {string[]} reasons List of rejection reason IDs (when accepted is false)
 */
AddImageArticleTarget.prototype.updateSuggestionState = function ( index, accepted, reasons ) {
	this.selectedImageIndex = index;
	this.recommendationAccepted = accepted;
	this.recommendationRejectionReasons = reasons;
};

/**
 * Return the selected image suggestion data
 *
 * @return {mw.libs.ge.ImageRecommendationImage}
 */
AddImageArticleTarget.prototype.getSelectedSuggestion = function () {
	return this.images[ this.selectedImageIndex ];
};

/** @inheritDoc **/
AddImageArticleTarget.prototype.onSaveComplete = function ( data ) {
	var imageRecWarningKey = 'geimagerecommendationdailytasksexceeded',
		geWarnings = data.gewarnings || [];

	geWarnings.forEach( function ( warning ) {
		if ( warning[ imageRecWarningKey ] ) {
			suggestedEditSession.qualityGateConfig[ 'image-recommendation' ] = { dailyLimit: true };
			suggestedEditSession.save();
		}
	} );
};

module.exports = AddImageArticleTarget;
