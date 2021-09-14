var StructuredTaskToolbarDialog = require( '../StructuredTaskToolbarDialog.js' ),
	MachineSuggestionsMode = require( '../MachineSuggestionsMode.js' ),
	suggestedEditSession = require( 'ext.growthExperiments.SuggestedEditSession' ).getInstance();

/**
 * @class mw.libs.ge.RecommendedImageToolbarDialog
 * @extends  mw.libs.ge.StructuredTaskToolbarDialog
 * @constructor
 */
function RecommendedImageToolbarDialog() {
	RecommendedImageToolbarDialog.super.apply( this, arguments );
	this.$element.addClass( [
		'mw-ge-recommendedImageToolbarDialog',
		OO.ui.isMobile() ?
			'mw-ge-recommendedImageToolbarDialog-mobile' :
			'mw-ge-recommendedImageToolbarDialog-desktop'
	] );

	/**
	 * @property {jQuery} $buttons Container for Yes, No, Skip buttons
	 */
	this.$buttons = $( '<div>' ).addClass( 'mw-ge-recommendedImageToolbarDialog-buttons' );

	/**
	 * @property {jQuery} $reason Suggestion reason
	 */
	this.$reason = $( '<div>' ).addClass( 'mw-ge-recommendedImageToolbarDialog-reason' );

	/**
	 * @property {jQuery} $imagePreview Container for image thumbnail and description
	 */
	this.$imagePreview = $( '<div>' ).addClass(
		'mw-ge-recommendedImageToolbarDialog-imagePreview'
	);
	/**
	 * @property {OO.ui.ToggleButtonWidget} yesButton
	 */
	this.yesButton = new OO.ui.ToggleButtonWidget( {
		icon: 'check',
		label: mw.message( 'growthexperiments-addimage-inspector-yes-button' ).text(),
		classes: [ 'mw-ge-recommendedImageToolbarDialog-buttons-yes' ]
	} );
	this.yesButton.connect( this, { click: [ 'onYesButtonClicked' ] } );

	/**
	 * @property {OO.ui.ToggleButtonWidget} noButton
	 */
	this.noButton = new OO.ui.ToggleButtonWidget( {
		icon: 'close',
		label: mw.message( 'growthexperiments-addimage-inspector-no-button' ).text(),
		classes: [ 'mw-ge-recommendedImageToolbarDialog-buttons-no' ]
	} );
	this.noButton.connect( this, { click: [ 'onNoButtonClicked' ] } );

	/**
	 * @property {OO.ui.ButtonWidget} skipButton
	 */
	this.skipButton = new OO.ui.ButtonWidget( {
		framed: false,
		label: mw.message( 'growthexperiments-addimage-inspector-skip-button' ).text(),
		classes: [ 'mw-ge-recommendedImageToolbarDialog-buttons-skip' ]
	} );
	this.skipButton.connect( this, { click: [ 'onSkipButtonClicked' ] } );

	/**
	 * @property {jQuery} $detailsButton
	 */
	this.$detailsButton = $( '<a>' )
		.attr( 'href', '#' )
		.addClass( 'mw-ge-recommendedImageToolbarDialog-details-button' )
		.text( mw.message( 'growthexperiments-addimage-inspector-details-button' ).text() );
	this.$detailsButton.on( 'click', this.onDetailsButtonClicked.bind( this ) );

	/**
	 * @property {Function} onDocumentNodeClick
	 */
	this.onDocumentNodeClick = this.hideDialog.bind( this );

	/**
	 * @property {Object[]} images
	 */
	this.images = suggestedEditSession.taskData.images;
}

OO.inheritClass( RecommendedImageToolbarDialog, StructuredTaskToolbarDialog );

RecommendedImageToolbarDialog.static.name = 'recommendedImage';
RecommendedImageToolbarDialog.static.size = 'full';
RecommendedImageToolbarDialog.static.position = 'below';

/** @inheritDoc **/
RecommendedImageToolbarDialog.prototype.initialize = function () {
	RecommendedImageToolbarDialog.super.prototype.initialize.call( this );
	var $title = $( '<span>' ).addClass( 'mw-ge-recommendedImageToolbarDialog-title' )
			.text(
				mw.message(
					'growthexperiments-addimage-inspector-title',
					[ mw.language.convertNumber( 1 ) ]
				).text()
			),
		$cta = $( '<p>' ).addClass( 'mw-ge-recommendedImageToolbarDialog-addImageCta' )
			.text( mw.message( 'growthexperiments-addimage-inspector-cta' ).text() );
	this.$head.append( [ this.getRobotIcon(), $title ] );
	this.setupButtons();
	this.$body.addClass( 'mw-ge-recommendedImageToolbarDialog-body' )
		.append( [ this.getBodyContent(), $cta, this.$buttons ] );
};

/** @inheritDoc **/
RecommendedImageToolbarDialog.prototype.getSetupProcess = function ( data ) {
	data = data || {};
	return RecommendedImageToolbarDialog.super.prototype.getSetupProcess.call( this, data )
		.next( function () {
			this.surface = data.surface;
			this.afterSetupProcess();
		}, this );
};

/**
 * Initialize elements after this.surface is set
 */
RecommendedImageToolbarDialog.prototype.afterSetupProcess = function () {
	MachineSuggestionsMode.disableVirtualKeyboard( this.surface );
	this.surface.getView().$documentNode.on( 'click', this.onDocumentNodeClick );
	this.setUpToolbarDialogButton(
		mw.message( 'growthexperiments-addimage-inspector-show-button' ).text()
	);
	// TBD: Desktop UI (help panel CTA button is shown by default)
	if ( OO.ui.isMobile() ) {
		this.setupHelpButton(
			mw.message( 'growthexperiments-addimage-inspector-help-button' ).text()
		);
	}
	this.showRecommendationAtIndex( 0 );
};

RecommendedImageToolbarDialog.prototype.onYesButtonClicked = function () {
	// TODO: Caption (T290781)
};

RecommendedImageToolbarDialog.prototype.onNoButtonClicked = function () {
	// TODO: handle state
	this.surface.dialogs.openWindow( 'recommendedImageRejection' );
};

RecommendedImageToolbarDialog.prototype.onSkipButtonClicked = function () {
	// TODO: Go to next suggested edit (T290910)
};

RecommendedImageToolbarDialog.prototype.onFullscreenButtonClicked = function () {
	// TODO: Image viewer (T290540)
};

RecommendedImageToolbarDialog.prototype.onDetailsButtonClicked = function ( e ) {
	e.preventDefault();
	// TODO: Show image details dialog
};

/**
 * Add event lissteners for Yes and No buttons and append them to this.$buttons
 */
RecommendedImageToolbarDialog.prototype.setupButtons = function () {
	var $acceptanceButtons = $( '<div>' ).addClass(
		'mw-ge-recommendedImageToolbarDialog-buttons-acceptance-group'
	);
	$acceptanceButtons.append( [ this.yesButton.$element, this.noButton.$element ] );
	this.$buttons.append( [ $acceptanceButtons, this.skipButton.$element ] );
};

/**
 * Set up dialog content and return the container element
 *
 * @return {jQuery}
 */
RecommendedImageToolbarDialog.prototype.getBodyContent = function () {
	var $bodyContent = $( '<div>' ).addClass(
		'mw-ge-recommendedImageToolbarDialog-bodyContent'
	);
	this.setupImagePreview();
	$bodyContent.append( [ this.$reason, this.$imagePreview ] );
	return $bodyContent;
};

/**
 * Set up image thumbnail and image fullscreen button
 */
RecommendedImageToolbarDialog.prototype.setupImagePreview = function () {
	this.$imageThumbnail = $( '<div>' ).addClass(
		'mw-ge-recommendedImageToolbarDialog-image-thumbnail'
	);
	this.$imageInfo = $( '<div>' ).addClass(
		'mw-ge-recommendedImageToolbarDialog-image-info'
	);
	this.fullscreenButton = new OO.ui.ButtonWidget( {
		icon: 'fullScreen',
		framed: false,
		classes: [ 'mw-ge-recommendedImageToolbarDialog-fullscreen-button' ]
	} );
	this.fullscreenButton.connect( this, { click: 'onFullscreenButtonClicked' } );
	this.$imageThumbnail.append( this.fullscreenButton.$element );
	this.$imagePreview.append( this.$imageThumbnail, this.$imageInfo );
};

/**
 * Show recommendation at the specified index
 *
 * @param {number} index Zero-based index of the recommendation in the images array
 */
RecommendedImageToolbarDialog.prototype.showRecommendationAtIndex = function ( index ) {
	this.currentIndex = index;
	this.updateSuggestionContent();
};

/**
 * Construct link element
 *
 * @param {string} title Link title
 * @param {string} url Link target
 * @return {jQuery}
 */
RecommendedImageToolbarDialog.prototype.getFileLinkElement = function ( title, url ) {
	return $( '<a>' )
		.addClass( 'mw-ge-recommendedImageToolbarDialog-file-link' )
		.attr( { href: url, target: '_blank' } ).text( title );
};

/**
 * Construct description element with text from the specified HTML string
 *
 * @param {string} descriptionHtml
 * @return {jQuery}
 */
RecommendedImageToolbarDialog.prototype.getDescriptionElement = function ( descriptionHtml ) {
	// TODO: Filter out complicated content in description (infoboxes, tables etc)
	return $( '<p>' )
		.addClass( 'mw-ge-recommendedImageToolbarDialog-description' )
		.html( $.parseHTML( descriptionHtml ) );
};

/**
 * Update content specific to the current suggestion
 */
RecommendedImageToolbarDialog.prototype.updateSuggestionContent = function () {
	var imageData = this.images[ this.currentIndex ],
		metadata = imageData.metadata;
	// TODO: format reason
	this.$reason.text( imageData.projects );
	this.$imageThumbnail.css( 'background-image', 'url(' + metadata.thumbUrl + ')' );
	this.$imageInfo.append( [
		$( '<div>' ).append( [
			this.getFileLinkElement( imageData.image, metadata.descriptionUrl ),
			this.getDescriptionElement( metadata.description )
		] ),
		$( '<div>' ).addClass( 'mw-ge-recommendedImageToolbarDialog-details-button-container' )
			.append( this.$detailsButton )
	] );
};

module.exports = RecommendedImageToolbarDialog;
