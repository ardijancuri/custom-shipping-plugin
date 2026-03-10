( function( $ ) {
	'use strict';

	function getDescendantCheckboxes( $container, termId ) {
		const descendants = [];
		const queue = [ String( termId ) ];

		while ( queue.length ) {
			const currentId = queue.shift();
			const $children = $container.find(
				'input[type="checkbox"][data-parent-term-id="' + currentId + '"]'
			);

			$children.each( function() {
				const $child = $( this );
				const childTermId = String( $child.data( 'term-id' ) );

				descendants.push( $child );
				queue.push( childTermId );
			} );
		}

		return descendants;
	}

	$( document.body ).on(
		'change',
		'.lesna-category-checkbox-list input[type="checkbox"][data-term-id]',
		function() {
			const $checkbox = $( this );
			const $container = $checkbox.closest( '.lesna-category-checkbox-list' );
			const descendants = getDescendantCheckboxes( $container, $checkbox.data( 'term-id' ) );

			descendants.forEach( function( $descendant ) {
				$descendant.prop( 'checked', $checkbox.prop( 'checked' ) );
			} );
		}
	);
} )( jQuery );
