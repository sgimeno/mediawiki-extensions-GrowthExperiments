var CeRecommendedLinkAnnotation = require( './ceRecommendedLinkAnnotation.js' ),
	suggestedEditSession = require( 'ext.growthExperiments.SuggestedEditSession' ).getInstance();
/**
 * @typedef LinkRecommendationLink
 * @property {string} phrase_to_link Text to look for
 * @property {string} link_target Name of the article the phrase should link to
 * @property {number} instance_occurrence Which occurrence of the phrase to look for, zero-indexed
 * @property {number} wikitext_offset The 0-based index of the first character of the link text
 *   in the wikitext, in Unicode characters.
 * @property {number} probability Probability/confidence that this is a good link
 * @property {string} context_before Small amount of text that occurs immediately before the phrase
 * @property {string} context_after Small amount of text that occurs immediately after the phrase
 * @property {number} insertion_order Position in the order of all recommendations, zero-indexed
 */

/**
 * Mixin for a ve.init.mw.ArticleTarget instance. Used by AddLinkDesktopArticleTarget and
 * AddLinkMobileArticleTarget.
 *
 * @mixin mw.libs.ge.AddLinkArticleTarget
 */
function AddLinkArticleTarget() {
}

/**
 * Implementations should call this in loadSuccess(), before calling the parent method.
 * It will modify the API response by adding link recommendation annotations to the
 * page HTML.
 *
 * @param {Object} response Response from the visualeditor or visualeditoredit API,
 *   passed to loadSuccess(). Will be modified.
 */
AddLinkArticleTarget.prototype.beforeLoadSuccess = function ( response ) {
	// TODO disable restored edits somehow, right now they break (T267690)
	var data, addlinkData, doc;

	if ( !response ) {
		return;
	}
	data = response.visualeditor || response.visualeditoredit;
	doc = ve.parseXhtml( data.content );
	addlinkData = suggestedEditSession.taskData;
	// TODO start loading this earlier (T267691)
	this.annotateSuggestions( doc, addlinkData.links );
	data.content = '<!doctype html>' + ve.serializeXhtml( doc );
};

/**
 * Implementations should call this in surfaceReady(), before calling the parent method.
 */
AddLinkArticleTarget.prototype.beforeSurfaceReady = function () {
	// Put the surface in read-only mode
	this.getSurface().setReadOnly( true );

	// HACK RecommendedLinkContextItem doesn't have access to the target, so give it access to the
	// link recommendation data by adding a property to the ui.Surface
	this.getSurface().linkRecommendationFragments = this.findRecommendationFragments();
};

AddLinkArticleTarget.prototype.afterSurfaceReady = function () {
	// Select the first recommendation
	// On mobile, the surface is not yet attached to the DOM when this runs, so wait for that to happen
	// On desktop, the surface is already attached, and we can do this immediately
	if ( OO.ui.isMobile() ) {
		this.overlay.on( 'editor-loaded', this.selectFirstRecommendation.bind( this ) );
	} else {
		this.selectFirstRecommendation();
	}
};

AddLinkArticleTarget.prototype.selectFirstRecommendation = function () {
	this.getSurface().linkRecommendationFragments[ 0 ].fragment.select();
	// Force the context item to appear
	// TODO perhaps deduplicate this code, figure out where to put it
	this.getSurface().getView().selectAnnotation( function ( annotationView ) {
		return annotationView instanceof CeRecommendedLinkAnnotation;
	} );
	if ( OO.ui.isMobile() ) {
		this.getSurface().getView().activate();
		this.getSurface().getView().deactivate( false, false, true );
		this.getSurface().getView().updateActiveAnnotations( true );
	}
};

AddLinkArticleTarget.prototype.restoreScrollPosition = function () {
	// Don't restore the saved scroll position, because we've selected the first link recommendation
	// and scrolled to it
};

/**
 * Find all mwGeRecommendedLink annotations in the document, and build a data structure with their
 * IDs and SurfaceFragments representing the range they cover. If the annotations move around
 * because of changes elsewhere in the document, these SurfaceFragments update automatically.
 *
 * @private
 * @return {{ recommendationWikitextOffset: number, fragment: ve.dm.SurfaceFragment }[]}
 */
AddLinkArticleTarget.prototype.findRecommendationFragments = function () {
	var i, annotations, thisRecommendationWikitextOffset,
		lastRecommendationWikitextOffset = null,
		surfaceModel = this.getSurface().getModel(),
		data = surfaceModel.getDocument().data,
		dataLength = data.getLength(),
		recommendationRanges = {};

	for ( i = 0; i < dataLength; i++ ) {
		// TODO maybe this could be more efficient (T267693)
		annotations = data.getAnnotationsFromOffset( i ).getAnnotationsByName( 'mwGeRecommendedLink' );
		if ( annotations.getLength() ) {
			thisRecommendationWikitextOffset = annotations.get( 0 )
				.getAttribute( 'recommendationWikitextOffset' );
			if ( thisRecommendationWikitextOffset === lastRecommendationWikitextOffset ) {
				// Continuation of the current annotation
				recommendationRanges[ lastRecommendationWikitextOffset ][ 1 ] = i + 1;
			} else {
				// Start of a new annotation
				recommendationRanges[ thisRecommendationWikitextOffset ] = [ i, i + 1 ];
				lastRecommendationWikitextOffset = thisRecommendationWikitextOffset;
			}
		} else {
			// If we had an activate annotation, mark it as having ended
			lastRecommendationWikitextOffset = null;
		}
	}

	return Object.keys( recommendationRanges )
		// Object.keys() is not guaranteed to return keys in insertion order, so sort the ranges
		// by start offset
		.sort( function ( a, b ) {
			return recommendationRanges[ a ][ 0 ] - recommendationRanges[ b ][ 0 ];
		} )
		.map( function ( recommendationWikitextOffset ) {
			return {
				recommendationWikitextOffset: recommendationWikitextOffset,
				fragment: surfaceModel.getLinearFragment( new ve.Range(
					recommendationRanges[ recommendationWikitextOffset ][ 0 ],
					recommendationRanges[ recommendationWikitextOffset ][ 1 ]
				) )
			};
		} );
};

/**
 * Find the suggested links in the document, and annotate them.
 *
 * Link recommendations are annotated by wrapping them in <span typeof="mw:RecommendedLink"> tags,
 * with the data-target attribute set to the suggested link target.
 *
 * When searching for phrases to annotate, word boundaries are implied on either side. For example,
 * if suggestions contains an element with { text: 'foo' }, this method will search for the regex
 * /\bfoo\b/
 *
 * @private
 * @param {HTMLDocument} doc Document to find and annotate links in
 * @param {LinkRecommendationLink[]} suggestions Description of suggested links
 */
AddLinkArticleTarget.prototype.annotateSuggestions = function ( doc, suggestions ) {
	var i, regex, textNode, nextNode, match, phrase, suggestion, anythingLeft, linkText,
		postText, linkWrapper,
		phraseMap = {},
		treeWalker = doc.createTreeWalker(
			doc.body,
			// eslint-disable-next-line no-bitwise
			NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT,
			{ acceptNode: function ( node ) {
				// Exclude the contents of templates; don't descend into these nodes
				// TODO flesh out with more exclusions, handle about groups (T267694)
				if ( node.getAttribute && node.getAttribute( 'typeof' ) === 'mw:Transclusion' ) {
					return NodeFilter.FILTER_REJECT;
				} else if ( node.nodeType === Node.TEXT_NODE ) {
					return NodeFilter.FILTER_ACCEPT;
				}
				// Skip element nodes that shouldn't be excluded; this doesn't return them (we only
				// want to return text nodes), but it does descned
				return NodeFilter.FILTER_SKIP;
			} }
		);

	/**
	 * Build a regex that matches any of the given phrases.
	 *
	 * @private
	 * @param {string[]} phrases
	 * @return {RegExp} A regex that looks like /phrase one|phrase two|..../g
	 */
	function buildRegex( phrases ) {
		return new RegExp( phrases.map( mw.util.escapeRegExp ).join( '|' ), 'g' );
	}

	// For each phrase, gather the link targets for that phrase and the occurrence number for each
	// link target, and start an occurrence counter. There will typically be only one link target
	// per phrase, but this data structure supports multiple link targets for different occurrences
	// of the same phrase.
	// If suggestions contains { text: 'foo', index: 2, target: 'bar' }, then
	// phraseMap will contain { 'foo': { occurrencesSeen: 0, linkTargets: { 2: 'bar' } } }
	for ( i = 0; i < suggestions.length; i++ ) {
		phrase = phraseMap[ suggestions[ i ].phrase_to_link ] = phraseMap[ suggestions[ i ].phrase_to_link ] || {
			occurrencesSeen: 0,
			suggestions: {}
		};
		phrase.suggestions[ suggestions[ i ].instance_occurrence - 1 ] = suggestions[ i ];
	}

	// Build a regex that matches any phrase in phraseMap. We remove phrases from phraseMap when
	// we've found them, so if phraseMap is empty we'll know we're done.
	regex = buildRegex( Object.keys( phraseMap ) );
	anythingLeft = Object.keys( phraseMap ).length > 0;
	textNode = treeWalker.nextNode();
	// TODO: deal with span-wrapped entities, and possibly with partially-annotated phrases (T267695)
	while ( anythingLeft && textNode ) {
		// Move the TreeWalker forward before we do anything. This avoids the need for confusing
		// trickery later if we change the DOM to add a wrapper
		nextNode = treeWalker.nextNode();

		// Using .exec() and .lastIndex allows us to find multiple matches of the regex in a loops
		regex.lastIndex = 0;
		while ( anythingLeft && ( match = regex.exec( textNode.data ) ) ) {
			phrase = phraseMap[ match[ 0 ] ];
			suggestion = phrase.suggestions[ phrase.occurrencesSeen ];
			if ( suggestion.link_target !== undefined ) {
				// Split the textNode in three parts: before the matched phrase (textNode),
				// the matched phrase (linkText), and the text after the matched phrase (postText)
				linkText = textNode.splitText( match.index );
				postText = linkText.splitText( match[ 0 ].length );
				// Wrap linkText in a <span typeof="mw:RecommendedLink"> tag
				linkWrapper = doc.createElement( 'span' );
				linkWrapper.setAttribute( 'typeof', 'mw:RecommendedLink' );
				linkWrapper.setAttribute( 'data-target', suggestion.link_target );
				// TODO probably use wikitext offset
				linkWrapper.setAttribute( 'data-wikitext-offset', suggestion.wikitext_offset );
				linkWrapper.appendChild( linkText );
				postText.parentNode.insertBefore( linkWrapper, postText );
				// In the next iteration of the loop, search postText for any additional phrase
				// matches, and reset regex.lastIndex accordingly
				textNode = postText;
				regex.lastIndex = 0;

				// Delete the link that we just found from phraseMap. If there are no more links for
				// this phrase, delete the phrase and rebuild the regex without it.
				delete phrase.suggestions[ phrase.occurrencesSeen ];
				if ( Object.keys( phrase.suggestions ).length === 0 ) {
					delete phraseMap[ match[ 0 ] ];
					regex = buildRegex( Object.keys( phraseMap ) );
					anythingLeft = Object.keys( phraseMap ).length > 0;
				}
			}
			phrase.occurrencesSeen++;
		}

		textNode = nextNode;
	}

};

module.exports = AddLinkArticleTarget;