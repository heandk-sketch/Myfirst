( function () {
	'use strict';

	var localizedData = window.eclData || {};
	var canvas = document.getElementById( 'ecl-chart' );
	var canvasPoints = null;
	if ( canvas && canvas.getAttribute( 'data-points' ) ) {
		canvasPoints = JSON.parse( canvas.getAttribute( 'data-points' ) );
	}
	var fallbackI18n = {
		reading: 'Læser billedet… (første gang kan tage lidt tid)',
		done: 'Tjek de fundne værdier, ret dem om nødvendigt, og gem.',
		notFound: 'Kunne ikke finde alle værdier. Udfyld dem manuelt.',
		failed: 'Billedet kunne ikke læses. Udfyld værdierne manuelt.',
		noData: 'Ingen data endnu.',
		winter: 'Vinter',
		spring: 'Forår',
		summer: 'Sommer',
		autumn: 'Efterår',
		axisLabel: 'Batterikapacitet (kWh)'
	};
	var today = new Date();
	var localToday = today.getFullYear() + '-' +
		String( today.getMonth() + 1 ).padStart( 2, '0' ) + '-' +
		String( today.getDate() ).padStart( 2, '0' );
	var i18n = {};
	var data = {
		points: Array.isArray( canvasPoints ) ? canvasPoints :
			( Array.isArray( localizedData.points ) ? localizedData.points : [] ),
		today: canvas && canvas.getAttribute( 'data-today' ) ?
			canvas.getAttribute( 'data-today' ) :
			( typeof localizedData.today === 'string' ? localizedData.today : localToday ),
		tesseractUrl: typeof localizedData.tesseractUrl === 'string' && localizedData.tesseractUrl ?
			localizedData.tesseractUrl :
			'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js'
	};
	Object.keys( fallbackI18n ).forEach( function ( key ) {
		i18n[ key ] = localizedData.i18n && localizedData.i18n[ key ] ?
			localizedData.i18n[ key ] :
			fallbackI18n[ key ];
	} );

	var percentInput = document.getElementById( 'ecl-percent' );
	var kwhInput = document.getElementById( 'ecl-kwh' );
	var preview = document.getElementById( 'ecl-capacity-preview' );
	var statusEl = document.getElementById( 'ecl-ocr-status' );
	var photoInput = document.getElementById( 'ecl-photo' );

	function toNumber( value ) {
		var n = parseFloat( String( value ).replace( ',', '.' ) );
		return isNaN( n ) ? 0 : n;
	}

	function updatePreview() {
		if ( ! preview || ! percentInput || ! kwhInput ) {
			return;
		}
		var percent = toNumber( percentInput.value );
		var kwh = toNumber( kwhInput.value );
		preview.textContent = percent > 0 && kwh > 0 ?
			'= ' + ( kwh / ( percent / 100 ) ).toFixed( 2 ) + ' kWh' :
			'';
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
			script.onerror = reject;
			document.head.appendChild( script );
		} );
	}

	function parseText( text ) {
		var rangeMatch = text.match( /(\d{1,3})\s*%?\s*[^\d\s%]{1,3}\s*(\d{1,3})\s*%/ );
		var percentMatch = text.match( /(\d{1,3}(?:[.,]\d+)?)\s*%/ );
		var kwhMatch = text.match( /(\d{1,3}(?:[.,]\d+)?)\s*k\s*w\s*[hn]/i );
		var from = '';
		var to = '';
		var percent = '';

		if ( rangeMatch && parseInt( rangeMatch[ 2 ], 10 ) > parseInt( rangeMatch[ 1 ], 10 ) ) {
			from = rangeMatch[ 1 ];
			to = rangeMatch[ 2 ];
			percent = String( parseInt( to, 10 ) - parseInt( from, 10 ) );
		} else if ( percentMatch ) {
			percent = percentMatch[ 1 ].replace( ',', '.' );
		}

		return {
			percent: percent,
			from: from,
			to: to,
			kwh: kwhMatch ? kwhMatch[ 1 ].replace( ',', '.' ) : ''
		};
	}

	function showResult( found ) {
		var ok = found.percent && found.kwh;
		var lines = [];
		lines.push( ( found.kwh ? '✔ ' : '✘ ' ) + 'Opladet energi: ' + ( found.kwh ? found.kwh + ' kWh' : 'ikke fundet' ) );
		if ( found.from ) {
			lines.push( '✔ SOC: ' + found.from + '% → ' + found.to + '% (= ' + found.percent + '% opladet)' );
		} else {
			lines.push( ( found.percent ? '✔ ' : '✘ ' ) + 'SOC: ' + ( found.percent ? found.percent + '% opladet' : 'ikke fundet' ) );
		}
		lines.push( ok ? i18n.done : i18n.notFound );
		statusEl.textContent = lines.join( '\n' );
		statusEl.style.whiteSpace = 'pre-line';
		statusEl.style.fontWeight = '600';
		statusEl.style.color = ok ? '#1a7f37' : '#b42318';
	}

	if ( photoInput ) {
		photoInput.addEventListener( 'change', function () {
			var file = photoInput.files && photoInput.files[ 0 ];
			if ( ! file ) {
				return;
			}
			statusEl.textContent = i18n.reading;

			loadTesseract()
				.then( function ( Tesseract ) {
					return Tesseract.recognize( file, 'eng' );
				} )
				.then( function ( result ) {
					var found = parseText( result.data.text || '' );
					if ( found.percent ) {
						percentInput.value = found.percent;
					}
					if ( found.kwh ) {
						kwhInput.value = found.kwh;
					}
					updatePreview();
					showResult( found );
				} )
				.catch( function () {
					statusEl.textContent = i18n.failed;
					statusEl.style.color = '#b42318';
				} );
		} );
	}

	if ( percentInput && kwhInput ) {
		percentInput.addEventListener( 'input', updatePreview );
		kwhInput.addEventListener( 'input', updatePreview );
	}

	var MONTHS = [ 'Jan', 'Feb', 'Mar', 'Apr', 'Maj', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec' ];

	function seasonOf( month ) {
		if ( month === 11 || month <= 1 ) {
			return 'winter';
		}
		if ( month <= 4 ) {
			return 'spring';
		}
		if ( month <= 7 ) {
			return 'summer';
		}
		return 'autumn';
	}

	var SEASON_COLORS = {
		winter: '#ffffff',
		spring: '#d9f2d0',
		summer: '#fff3b0',
		autumn: '#ffd9b3'
	};

	function parseDate( str ) {
		if ( typeof str !== 'string' || ! /^\d{4}-\d{2}-\d{2}$/.test( str ) ) {
			return NaN;
		}
		var p = str.split( '-' );
		return Date.UTC( parseInt( p[ 0 ], 10 ), parseInt( p[ 1 ], 10 ) - 1, parseInt( p[ 2 ], 10 ) );
	}

	function monthStart( t ) {
		var d = new Date( t );
		return new Date( Date.UTC( d.getUTCFullYear(), d.getUTCMonth(), 1 ) );
	}

	function drawChart() {
		if ( ! canvas || ! canvas.getContext ) {
			return;
		}

		var dpr = window.devicePixelRatio || 1;
		var width = canvas.clientWidth || 600;
		var height = 320;
		canvas.width = width * dpr;
		canvas.height = height * dpr;
		canvas.style.height = height + 'px';

		var ctx = canvas.getContext( '2d' );
		ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );
		ctx.clearRect( 0, 0, width, height );
		ctx.font = '12px sans-serif';

		var m = { l: 48, r: 12, t: 12, b: 56 };
		var plotW = width - m.l - m.r;
		var plotH = height - m.t - m.b;

		var end = parseDate( data.today );
		var endDate = new Date( end );
		var start = Date.UTC( endDate.getUTCFullYear() - 2, endDate.getUTCMonth(), endDate.getUTCDate() );

		function x( t ) {
			return m.l + ( ( t - start ) / ( end - start ) ) * plotW;
		}

		// Season bands, one segment per month.
		var cursor = monthStart( start );
		while ( cursor.getTime() < end ) {
			var segStart = Math.max( cursor.getTime(), start );
			var next = new Date( Date.UTC( cursor.getUTCFullYear(), cursor.getUTCMonth() + 1, 1 ) );
			var segEnd = Math.min( next.getTime(), end );
			ctx.fillStyle = SEASON_COLORS[ seasonOf( cursor.getUTCMonth() ) ];
			ctx.fillRect( x( segStart ), m.t, x( segEnd ) - x( segStart ), plotH );
			cursor = next;
		}

		ctx.strokeStyle = '#999';
		ctx.strokeRect( m.l, m.t, plotW, plotH );

		var points = ( data.points || [] ).map( function ( p ) {
			return { t: parseDate( p.date ), v: p.capacity };
		} );

		// Quarter labels on the x axis.
		ctx.fillStyle = '#333';
		ctx.textAlign = 'center';
		var tick = monthStart( start );
		while ( tick.getTime() <= end ) {
			if ( tick.getTime() >= start && tick.getUTCMonth() % 3 === 0 ) {
				var tx = x( tick.getTime() );
				ctx.strokeStyle = '#bbb';
				ctx.beginPath();
				ctx.moveTo( tx, m.t + plotH );
				ctx.lineTo( tx, m.t + plotH + 4 );
				ctx.stroke();
				ctx.fillText( MONTHS[ tick.getUTCMonth() ] + ' ' + String( tick.getUTCFullYear() ).slice( 2 ), tx, m.t + plotH + 18 );
			}
			tick = new Date( Date.UTC( tick.getUTCFullYear(), tick.getUTCMonth() + 1, 1 ) );
		}

		// Legend.
		var legend = [ 'winter', 'spring', 'summer', 'autumn' ];
		var lx = m.l;
		var ly = height - 18;
		ctx.textAlign = 'left';
		legend.forEach( function ( key ) {
			ctx.fillStyle = SEASON_COLORS[ key ];
			ctx.fillRect( lx, ly - 10, 14, 14 );
			ctx.strokeStyle = '#999';
			ctx.strokeRect( lx, ly - 10, 14, 14 );
			ctx.fillStyle = '#333';
			ctx.fillText( i18n[ key ] || key, lx + 20, ly + 1 );
			lx += 32 + ctx.measureText( i18n[ key ] || key ).width + 12;
		} );

		if ( ! points.length ) {
			ctx.fillStyle = '#555';
			ctx.textAlign = 'center';
			ctx.fillText( i18n.noData, m.l + plotW / 2, m.t + plotH / 2 );
			return;
		}

		var values = points.map( function ( p ) {
			return p.v;
		} );
		var min = Math.min.apply( null, values );
		var max = Math.max.apply( null, values );
		if ( min === max ) {
			min -= 1;
			max += 1;
		}
		var pad = ( max - min ) * 0.1;
		min -= pad;
		max += pad;

		function y( v ) {
			return m.t + plotH - ( ( v - min ) / ( max - min ) ) * plotH;
		}

		// Y axis ticks.
		ctx.textAlign = 'right';
		for ( var i = 0; i <= 4; i++ ) {
			var value = min + ( ( max - min ) * i ) / 4;
			var ty = y( value );
			ctx.strokeStyle = 'rgba(0,0,0,0.12)';
			ctx.beginPath();
			ctx.moveTo( m.l, ty );
			ctx.lineTo( m.l + plotW, ty );
			ctx.stroke();
			ctx.fillStyle = '#333';
			ctx.fillText( value.toFixed( 1 ), m.l - 6, ty + 4 );
		}

		ctx.strokeStyle = '#0d6efd';
		ctx.lineWidth = 2;
		ctx.beginPath();
		points.forEach( function ( p, index ) {
			if ( index === 0 ) {
				ctx.moveTo( x( p.t ), y( p.v ) );
			} else {
				ctx.lineTo( x( p.t ), y( p.v ) );
			}
		} );
		ctx.stroke();
		ctx.lineWidth = 1;

		ctx.fillStyle = '#0d6efd';
		points.forEach( function ( p ) {
			ctx.beginPath();
			ctx.arc( x( p.t ), y( p.v ), 3, 0, Math.PI * 2 );
			ctx.fill();
		} );

		ctx.save();
		ctx.translate( 12, m.t + plotH / 2 );
		ctx.rotate( -Math.PI / 2 );
		ctx.textAlign = 'center';
		ctx.fillStyle = '#333';
		ctx.fillText( i18n.axisLabel || '', 0, 0 );
		ctx.restore();
	}

	drawChart();
	window.addEventListener( 'resize', drawChart );
}() );
