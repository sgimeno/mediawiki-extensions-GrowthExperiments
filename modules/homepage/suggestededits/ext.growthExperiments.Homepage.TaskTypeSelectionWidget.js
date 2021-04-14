/**
 * @class
 * @extends OO.ui.Widget
 *
 * @constructor
 * @param {Object} config
 * @param {string[]} config.selectedTaskTypes Pre-selected task types
 * @param {Object} config.introLinks Link targets for fake task types; must contain a 'create' key
 */
function TaskTypeSelectionWidget( config ) {
	// Parent constructor
	TaskTypeSelectionWidget.super.call( this, config );

	this.preselectedTaskTypes = config.selectedTaskTypes || [];
	this.introLinks = config.introLinks;

	this.buildCheckboxFilters();
	this.errorMessage = new OO.ui.MessageWidget( {
		type: 'error',
		inline: true,
		classes: [ 'mw-ge-homepage-taskTypeSelectionWidget-error' ],
		label: mw.message( 'growthexperiments-homepage-suggestededits-difficulty-filter-error' ).text()
	} ).toggle( false );

	this.$element.append(
		this.errorMessage.$element,
		this.makeHeadersForDifficulty( 'easy' ),
		this.easyFilters.$element,
		this.makeHeadersForDifficulty( 'medium' ),
		this.mediumFilters.$element,
		this.makeHeadersForDifficulty( 'hard' ),
		this.hardFilters.$element
	)
		.addClass( 'mw-ge-homepage-taskTypeSelectionWidget' );
}

OO.inheritClass( TaskTypeSelectionWidget, OO.ui.Widget );

/**
 * Return an array of enabled task types to use for searching.
 *
 * @return {string[]}
 */
TaskTypeSelectionWidget.prototype.getSelected = function () {
	return this.easyFilters.findSelectedItemsData()
		.concat( this.mediumFilters.findSelectedItemsData() )
		.concat( this.hardFilters.findSelectedItemsData() );
};

TaskTypeSelectionWidget.prototype.onSelect = function () {
	var selected = this.getSelected();
	this.errorMessage.toggle( selected.length === 0 );
	this.emit( 'select', selected );
};

/**
 * Select the given task types.
 *
 * @param {string[]} taskTypes
 */
TaskTypeSelectionWidget.prototype.setSelected = function ( taskTypes ) {
	this.easyFilters.selectItemsByData( taskTypes );
	this.mediumFilters.selectItemsByData( taskTypes );
	this.hardFilters.selectItemsByData( taskTypes );
};

TaskTypeSelectionWidget.prototype.buildCheckboxFilters = function () {
	this.createFilter = this.makeCheckbox( {
		id: 'create',
		difficulty: 'hard',
		messages: {
			label: mw.message( 'growthexperiments-homepage-suggestededits-tasktype-label-create' ).text()
		},
		disabled: true,
		iconData: {}
	}, false );
	this.createFilter.$element.append( $( '<div>' )
		.addClass( 'mw-ge-homepage-taskTypeSelectionWidget-additional-msg' )
		.html(
			mw.message( 'growthexperiments-homepage-suggestededits-create-article-additional-message' )
				.params( [ mw.user, mw.util.getUrl( this.introLinks.create ) ] )
				.parse()
		)
	);

	this.easyFilters = new OO.ui.CheckboxMultiselectWidget( {
		items: this.makeCheckboxesForDifficulty( 'easy', this.preselectedTaskTypes )
	} ).connect( this, { select: 'onSelect' } );

	this.mediumFilters = new OO.ui.CheckboxMultiselectWidget( {
		items: this.makeCheckboxesForDifficulty( 'medium', this.preselectedTaskTypes )
	} ).connect( this, { select: 'onSelect' } );

	this.hardFilters = new OO.ui.CheckboxMultiselectWidget( {
		items: this.makeCheckboxesForDifficulty( 'hard', this.preselectedTaskTypes )
			.concat( [ this.createFilter ] )
	} ).connect( this, { select: 'onSelect' } );
};

/**
 * @param {string} difficulty 'easy', 'medium' or 'hard'
 * @return {jQuery}
 */
TaskTypeSelectionWidget.prototype.makeHeadersForDifficulty = function ( difficulty ) {
	// The following icons are used here:
	// * difficulty-easy
	// * difficulty-medium
	// * difficulty-hard
	var iconWidget = new OO.ui.IconWidget( { icon: 'difficulty-' + difficulty } ),
		$label = $( '<h4>' )
			.addClass( 'mw-ge-homepage-taskTypeSelectionWidget-difficulty-level-label' )
			.text( mw.message(
				// The following messages are used here:
				// * growthexperiments-homepage-startediting-dialog-difficulty-level-easy-label
				// * growthexperiments-homepage-startediting-dialog-difficulty-level-medium-label
				// * growthexperiments-homepage-startediting-dialog-difficulty-level-hard-label
				'growthexperiments-homepage-startediting-dialog-difficulty-level-' + difficulty + '-label'
			).text() ),
		$description = $( '<p>' )
			.addClass( 'mw-ge-homepage-taskTypeSelectionWidget-difficulty-level-desc' )
			.text( mw.message(
				// The following messages are used here:
				// * growthexperiments-homepage-startediting-dialog-difficulty-level-easy-description-header
				// * growthexperiments-homepage-startediting-dialog-difficulty-level-medium-description-header
				// * growthexperiments-homepage-startediting-dialog-difficulty-level-hard-description-header
				'growthexperiments-homepage-startediting-dialog-difficulty-level-' + difficulty + '-description-header'
			).params( [ mw.user ] ).text() );

	return $( '<div>' )
		// The following classes are used here:
		// * mw-ge-homepage-taskTypeSelectionWidget-difficulty-level-easy
		// * mw-ge-homepage-taskTypeSelectionWidget-difficulty-level-medium
		// * mw-ge-homepage-taskTypeSelectionWidget-difficulty-level-hard
		.addClass(
			'mw-ge-homepage-taskTypeSelectionWidget-difficulty-level ' +
			'mw-ge-homepage-taskTypeSelectionWidget-difficulty-level-' + difficulty
		)
		.append( iconWidget.$element, $label, $description );
};

/**
 * @param {string} difficulty 'easy', 'medium' or 'hard'
 * @param {string[]} selectedTaskTypes Pre-selected task types
 * @return {OO.ui.CheckboxMultioptionWidget[]}
 */
TaskTypeSelectionWidget.prototype.makeCheckboxesForDifficulty = function ( difficulty, selectedTaskTypes ) {
	var taskType,
		taskTypes = require( './TaskTypes.json' ),
		checkboxes = [];
	for ( taskType in taskTypes ) {
		if ( taskTypes[ taskType ].difficulty === difficulty ) {
			checkboxes.push( this.makeCheckbox(
				taskTypes[ taskType ],
				selectedTaskTypes.indexOf( taskTypes[ taskType ].id ) !== -1
			) );
		}
	}
	return checkboxes;
};

/**
 * @param {Object} taskTypeData
 * @param {boolean} selected
 * @return {OO.ui.CheckboxMultioptionWidget}
 */
TaskTypeSelectionWidget.prototype.makeCheckbox = function ( taskTypeData, selected ) {
	var $checkboxIcon, descriptionMessage, $label = $( '<span>' ).text( taskTypeData.messages.label );
	if ( 'filterIcon' in taskTypeData.iconData ) {
		// Messages that can be used here:
		// * growthexperiments-homepage-suggestededits-tasktype-machine-description
		// * FORMAT growthexperiments-homepage-suggestededits-tasktype-{other}-description
		descriptionMessage = mw.message( taskTypeData.iconData.descriptionMessageKey ).text();
		$checkboxIcon = new OO.ui.Element().$element;
		$checkboxIcon.append(
			new OO.ui.IconWidget( { icon: taskTypeData.iconData.filterIcon } ).$element,
			new OO.ui.LabelWidget( { label: descriptionMessage, classes: [ 'mw-ge-tasktype-icon-label' ] } ).$element
		);
		$label.append( $checkboxIcon );
	}
	return new OO.ui.CheckboxMultioptionWidget( {
		data: taskTypeData.id,
		label: $label,
		selected: !!selected,
		disabled: !!taskTypeData.disabled,
		// The following classes are used here:
		// * mw-ge-homepage-taskTypeSelectionWidget-checkbox-copyedit
		// * mw-ge-homepage-taskTypeSelectionWidget-checkbox-create
		// * mw-ge-homepage-taskTypeSelectionWidget-checkbox-expand
		// * mw-ge-homepage-taskTypeSelectionWidget-checkbox-links
		// * mw-ge-homepage-taskTypeSelectionWidget-checkbox-update
		classes: [ 'mw-ge-homepage-taskTypeSelectionWidget-checkbox-' + taskTypeData.id ]
	} );
};

module.exports = TaskTypeSelectionWidget;
