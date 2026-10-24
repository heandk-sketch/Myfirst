( function () {
	'use strict';

	var data = window.golfscoreScanData;
	var fileInput = document.getElementById( 'golfscore-scan-file' );
	var statusEl = document.getElementById( 'golfscore-scan-status' );
	var reviewEl = document.getElementById( 'golfscore-scan-review' );

	/**
	 * Turn OCR text into players with 9 or 18 scores. Numbers above 15
	 * (Out/In/Total sums) are ignored. Lines that do not fit are skipped.
	 */
	function parseScoreboard( text ) {
		var players = [];
		String( text ).split( /\r?\n/ ).forEach( function ( line ) {
			var nameMatch = line.match( /^\s*([A-Za-zÆØÅæøå][A-Za-zÆØÅæøå .'-]{1,30}?)\s*(?=[\d|:\s])/ );
			if ( ! nameMatch ) {
				return;
			}
			var rest = line.slice( nameMatch[ 0 ].length );
			var numbers = ( rest.match( /\d+/g ) || [] )
				.map( Number )
				.filter( function ( n ) {
					return n >= 1 && n <= 15;
				} );
			if ( 9 === numbers.length || 18 === numbers.length ) {
				players.push( { name: nameMatch[ 1 ].trim(), scores: numbers } );
			} else if ( numbers.length > 18 ) {
				players.push( { name: nameMatch[ 1 ].trim(), scores: numbers.slice( 0, 18 ) } );
			}
		} );
		return players.slice( 0, 8 );
	}

	if ( ! fileInput ) {
		return;
	}

	function setStatus( message, isError ) {
		statusEl.textContent = message;
		statusEl.className = 'golfscore-scan-status' + ( isError ? ' error' : '' );
	}

	function loadImage( file ) {
		return new Promise( function ( resolve, reject ) {
			var url = URL.createObjectURL( file );
			var img = new Image();
			img.onload = function () {
				URL.revokeObjectURL( url );
				resolve( img );
			};
			img.onerror = function () {
				URL.revokeObjectURL( url );
				reject( new Error( 'Image could not be loaded.' ) );
			};
			img.src = url;
		} );
	}

	// Downscale and convert to high-contrast grayscale for better OCR.
	function prepareCanvas( img ) {
		var scale = Math.min( 1, 1800 / Math.max( img.width, img.height ) );
		var canvas = document.createElement( 'canvas' );
		canvas.width = Math.round( img.width * scale );
		canvas.height = Math.round( img.height * scale );
		var ctx = canvas.getContext( '2d' );
		ctx.drawImage( img, 0, 0, canvas.width, canvas.height );
		var pixels = ctx.getImageData( 0, 0, canvas.width, canvas.height );
		var d = pixels.data;
		for ( var i = 0; i < d.length; i += 4 ) {
			var gray = 0.299 * d[ i ] + 0.587 * d[ i + 1 ] + 0.114 * d[ i + 2 ];
			gray = Math.max( 0, Math.min( 255, ( gray - 128 ) * 1.6 + 128 ) );
			d[ i ] = d[ i + 1 ] = d[ i + 2 ] = gray;
		}
		ctx.putImageData( pixels, 0, 0 );
		return canvas;
	}

	function loadTesseract() {
		return new Promise( function ( resolve, reject ) {
			if ( window.Tesseract ) {
				resolve( window.Tesseract );
				return;
			}
			var script = document.createElement( 'script' );
			script.src = data.tesseractUrl;
			script.onload = function () {
				resolve( window.Tesseract );
			};
			script.onerror = function () {
				reject( new Error( 'OCR library could not be loaded.' ) );
			};
			document.head.appendChild( script );
		} );
	}

	function canvasToBlob( canvas ) {
		return new Promise( function ( resolve ) {
			canvas.toBlob( resolve, 'image/jpeg', 0.8 );
		} );
	}

	function post( body ) {
		return fetch( data.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) {
			return r.json();
		} );
	}

	function storeForManual( file, img ) {
		// Re-encode a smaller colour copy so the stored photo stays well below the size limit.
		var scale = Math.min( 1, 1800 / Math.max( img.width, img.height ) );
		var canvas = document.createElement( 'canvas' );
		canvas.width = Math.round( img.width * scale );
		canvas.height = Math.round( img.height * scale );
		canvas.getContext( '2d' ).drawImage( img, 0, 0, canvas.width, canvas.height );
		return canvasToBlob( canvas ).then( function ( blob ) {
			var form = new FormData();
			form.append( 'action', 'golfscore_scan_store_image' );
			form.append( 'nonce', data.nonce );
			form.append( 'photo', blob, 'scan.jpg' );
			return post( form );
		} ).then( function ( res ) {
			if ( res.success ) {
				setStatus( res.data.message + ' (' + res.data.pending + '/' + data.maxPending + ')', false );
			} else {
				setStatus( res.data && res.data.message ? res.data.message : 'Could not save the photo.', true );
			}
		} );
	}

	// OCR could not read the card: show the photo with an empty grid (Spiller #1-#5, 18 holes).
	function renderManual( file, img ) {
		var players = [];
		for ( var p = 1; p <= 5; p++ ) {
			players.push( { name: 'Spiller #' + p, scores: new Array( 18 ).fill( '' ) } );
		}
		renderReview( players );

		var preview = document.createElement( 'img' );
		preview.src = URL.createObjectURL( file );
		preview.alt = '';
		preview.style.cssText = 'max-width:100%;height:auto;margin:10px 0;display:block;';
		reviewEl.insertBefore( preview, reviewEl.firstChild );

		var later = document.createElement( 'button' );
		later.type = 'button';
		later.className = 'button';
		later.style.marginLeft = '8px';
		later.textContent = 'Save photo for later (PC)';
		later.addEventListener( 'click', function () {
			later.disabled = true;
			storeForManual( file, img ).then( function () {
				reviewEl.style.display = 'none';
			} );
		} );
		reviewEl.appendChild( later );
	}

	function renderReview( players ) {
		reviewEl.innerHTML = '';
		var table = document.createElement( 'table' );
		table.className = 'golfscore-scorecard-table';
		players.forEach( function ( player ) {
			var row = table.insertRow();
			var nameCell = row.insertCell();
			var nameInput = document.createElement( 'input' );
			nameInput.type = 'text';
			nameInput.value = player.name;
			nameInput.className = 'golfscore-scan-name';
			nameCell.appendChild( nameInput );
			player.scores.forEach( function ( score ) {
				var cell = row.insertCell();
				var input = document.createElement( 'input' );
				input.type = 'number';
				input.min = '1';
				input.max = '15';
				input.value = score;
				input.className = 'golfscore-scan-score';
				input.style.width = '3em';
				cell.appendChild( input );
			} );
		} );
		var wrap = document.createElement( 'div' );
		wrap.style.overflowX = 'auto';
		wrap.appendChild( table );
		reviewEl.appendChild( wrap );

		var save = document.createElement( 'button' );
		save.type = 'button';
		save.className = 'button button-primary';
		save.textContent = 'Save scoreboard';
		save.addEventListener( 'click', function () {
			var result = [];
			Array.prototype.forEach.call( table.rows, function ( row ) {
				var scores = Array.prototype.map.call( row.querySelectorAll( '.golfscore-scan-score' ), function ( i ) {
					return i.value;
				} );
				// Skip players without any scores (empty columns on the card).
				if ( scores.every( function ( s ) {
					return '' === s;
				} ) ) {
					return;
				}
				result.push( { name: row.querySelector( '.golfscore-scan-name' ).value, scores: scores } );
			} );
			if ( 0 === result.length ) {
				setStatus( 'Enter at least one player with scores.', true );
				return;
			}
			var form = new FormData();
			form.append( 'action', 'golfscore_scan_save_result' );
			form.append( 'nonce', data.nonce );
			form.append( 'players', JSON.stringify( result ) );
			save.disabled = true;
			post( form ).then( function ( res ) {
				if ( res.success ) {
					setStatus( res.data.message, false );
					reviewEl.style.display = 'none';
				} else {
					setStatus( res.data && res.data.message ? res.data.message : 'Save failed.', true );
					save.disabled = false;
				}
			} );
		} );
		reviewEl.appendChild( save );
		reviewEl.style.display = 'block';
	}

	fileInput.addEventListener( 'change', function () {
		var file = fileInput.files && fileInput.files[ 0 ];
		if ( ! file ) {
			return;
		}
		reviewEl.style.display = 'none';
		setStatus( 'Reading scoreboard…', false );

		var image;
		loadImage( file )
			.then( function ( img ) {
				image = img;
				return Promise.all( [ loadTesseract(), prepareCanvas( img ) ] );
			} )
			.then( function ( parts ) {
				return parts[ 0 ].recognize( parts[ 1 ], 'eng' );
			} )
			.then( function ( result ) {
				var players = parseScoreboard( result.data.text );
				if ( 0 === players.length ) {
					setStatus( 'Could not read the card automatically. Type the scores from the photo below, or save the photo for later.', false );
					renderManual( file, image );
					return;
				}
				setStatus( 'Check the values below and correct any mistakes, then save.', false );
				renderReview( players );
			} )
			.catch( function ( error ) {
				if ( image ) {
					return storeForManual( file, image );
				}
				setStatus( error.message, true );
			} )
			.then( function () {
				fileInput.value = '';
			} );
	} );
}() );
