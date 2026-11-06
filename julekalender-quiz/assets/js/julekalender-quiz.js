( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.jkq-card' ).forEach( function ( card ) {
			var door = card.querySelector( '.jkq-door__button' );
			var question = card.querySelector( '.jkq-question-panel h2' );

			if ( ! door || ! question ) {
				return;
			}

			card.classList.add( 'jkq-card--js' );
			door.addEventListener( 'click', function () {
				card.classList.add( 'jkq-card--opened' );
				door.setAttribute( 'aria-expanded', 'true' );
				window.setTimeout( function () {
					question.focus();
				}, 350 );
			} );
		} );
	} );
}() );
