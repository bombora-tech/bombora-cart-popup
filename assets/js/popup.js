/*!
 * Bombora Cart Popup – frontend (vanilla JS, bez závislostí).
 * Bezpečnost: HTML vkládáme pouze z vlastních endpointů (serverově escapované); texty z konfigurace
 * vkládáme výhradně přes textContent / setAttribute. Každý požadavek nese hlavičku X-Bombora-Cart.
 */
( function () {
	'use strict';

	var cfg = window.bcpConfig;
	if ( ! cfg || ! window.fetch || ! window.FormData || ! window.Promise ) {
		return;
	}

	var root = null;
	var dialog = null;
	var body = null;
	var relWrap = null;
	var lastFocus = null;
	var busy = false;
	var selfTrigger = false;
	var seq = 0;
	var timers = {};
	var mq = window.matchMedia ? window.matchMedia( '(max-width: 768px)' ) : null;

	function enabled() {
		return cfg.mobile || ! ( mq && mq.matches );
	}

	function endpoint( name ) {
		return String( cfg.endpoint ).replace( '%%endpoint%%', name );
	}

	function post( name, data ) {
		return fetch( endpoint( name ), {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'X-Bombora-Cart': '1', Accept: 'application/json' },
			body: data
		} ).then( function ( r ) {
			return r.json().catch( function () {
				return { ok: false };
			} );
		} );
	}

	/* ---------- Formátování ceny (okamžitý přepočet řádku) ---------- */
	function formatPrice( n ) {
		var c = cfg.currency || {};
		var d = parseInt( c.decimals, 10 ) || 0;
		var parts = Math.abs( n ).toFixed( d ).split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, c.thou || '' );
		var num = parts[ 1 ] ? parts[ 0 ] + ( c.dec || '.' ) + parts[ 1 ] : parts[ 0 ];
		var out = String( c.format || '%1$s%2$s' ).replace( '%1$s', c.symbol || '' ).replace( '%2$s', num );
		return ( n < 0 ? '-' : '' ) + out;
	}

	/* ---------- Dialog ---------- */
	function build() {
		if ( root ) {
			return;
		}
		root = document.createElement( 'div' );
		root.className = 'bcp';
		root.hidden = true;

		var overlay = document.createElement( 'div' );
		overlay.className = 'bcp-overlay';
		overlay.setAttribute( 'data-bcp-close', '' );

		dialog = document.createElement( 'div' );
		dialog.className = 'bcp-dialog';
		dialog.setAttribute( 'role', 'dialog' );
		dialog.setAttribute( 'aria-modal', 'true' );
		dialog.setAttribute( 'aria-labelledby', 'bcp-title' );
		dialog.setAttribute( 'aria-label', ( cfg.i18n && cfg.i18n.dialog ) || 'Cart' );
		dialog.tabIndex = -1;

		var closeBtn = document.createElement( 'button' );
		closeBtn.type = 'button';
		closeBtn.className = 'bcp-close';
		closeBtn.setAttribute( 'data-bcp-close', '' );
		closeBtn.setAttribute( 'aria-label', ( cfg.i18n && cfg.i18n.close ) || 'Close' );
		closeBtn.textContent = '×';

		body = document.createElement( 'div' );
		body.className = 'bcp-body';
		relWrap = document.createElement( 'div' );
		relWrap.className = 'bcp-rel-wrap';

		dialog.appendChild( closeBtn );
		dialog.appendChild( body );
		dialog.appendChild( relWrap );
		root.appendChild( overlay );
		root.appendChild( dialog );
		document.body.appendChild( root );

		root.addEventListener( 'click', onClick );
		root.addEventListener( 'input', onInput );
		root.addEventListener( 'change', onInput );
		document.addEventListener( 'keydown', onKey );
	}

	function open( res ) {
		build();
		if ( typeof res.html === 'string' ) {
			body.innerHTML = res.html;
		}
		if ( typeof res.related === 'string' ) {
			relWrap.innerHTML = res.related;
		}
		applyFragments( res.fragments, res.cart_hash );
		if ( root.hidden ) {
			lastFocus = document.activeElement;
			root.hidden = false;
			document.documentElement.classList.add( 'bcp-lock' );
			window.requestAnimationFrame( function () {
				root.classList.add( 'is-open' );
			} );
			dialog.focus( { preventScroll: true } );
		}
	}

	function close() {
		if ( ! root || root.hidden ) {
			return;
		}
		root.classList.remove( 'is-open' );
		root.hidden = true;
		document.documentElement.classList.remove( 'bcp-lock' );
		if ( lastFocus && typeof lastFocus.focus === 'function' ) {
			lastFocus.focus( { preventScroll: true } );
		}
	}

	function showError( message ) {
		build();
		var n = body.querySelector( '.bcp-notice' );
		if ( ! n ) {
			body.textContent = '';
			n = document.createElement( 'div' );
			n.className = 'bcp-notice';
			n.setAttribute( 'role', 'alert' );
			body.appendChild( n );
			relWrap.textContent = '';
		}
		n.textContent = message || ( cfg.i18n && cfg.i18n.error ) || 'Error';
		n.hidden = false;
		if ( root.hidden ) {
			open( {} );
		}
	}

	/* ---------- Fragmenty WooCommerce (hlavička, mini-košík, WPify lišta) ---------- */
	function applyFragments( fragments, hash ) {
		if ( ! fragments || typeof fragments !== 'object' ) {
			return;
		}
		Object.keys( fragments ).forEach( function ( selector ) {
			var nodes;
			try {
				nodes = document.querySelectorAll( selector );
			} catch ( e ) {
				return;
			}
			Array.prototype.forEach.call( nodes, function ( node ) {
				// Stejně jako jQuery.replaceWith ve WooCommerce: nahradit celým obsahem fragmentu
				// (např. WPify posílá <style> + <div> obal).
				var tpl = document.createElement( 'template' );
				tpl.innerHTML = String( fragments[ selector ] );
				if ( tpl.content.childNodes.length ) {
					node.replaceWith( tpl.content.cloneNode( true ) );
				}
			} );
		} );

		// Synchronizace s cache fragmentů WooCommerce (cart-fragments.js).
		var p = window.wc_cart_fragments_params;
		if ( p && window.sessionStorage ) {
			try {
				window.sessionStorage.setItem( p.fragment_name, JSON.stringify( fragments ) );
				if ( hash ) {
					window.sessionStorage.setItem( p.cart_hash_key, hash );
					window.localStorage.setItem( p.cart_hash_key, hash );
				}
			} catch ( e ) {}
		}
		if ( window.jQuery ) {
			window.jQuery( document.body ).trigger( 'wc_fragments_refreshed' );
		}
	}

	/* ---------- Přidání do košíku ---------- */
	function setLoading( btn, state ) {
		if ( ! btn ) {
			return;
		}
		btn.classList.toggle( 'loading', state );
		btn.disabled = state;
		btn.setAttribute( 'aria-busy', state ? 'true' : 'false' );
	}

	function addToCart( fd, btn ) {
		busy = true;
		setLoading( btn, true );
		return post( 'bcp_add', fd )
			.then( function ( res ) {
				if ( res && res.ok ) {
					open( res );
					if ( window.jQuery ) {
						selfTrigger = true;
						try {
							window.jQuery( document.body ).trigger( 'added_to_cart', [ res.fragments, res.cart_hash, window.jQuery( btn ) ] );
						} finally {
							selfTrigger = false;
						}
					}
				} else {
					showError( res && res.message );
				}
			} )
			.catch( function () {
				showError();
			} )
			.then( function () {
				busy = false;
				setLoading( btn, false );
			} );
	}

	// Formulář na detailu produktu (simple, bundle WPC, varianty…) – přidání zajišťuje WooCommerce.
	document.addEventListener(
		'submit',
		function ( e ) {
			if ( ! cfg.single || ! enabled() ) {
				return;
			}
			var form = e.target;
			if ( ! ( form instanceof HTMLFormElement ) || ! form.classList.contains( 'cart' ) || form.closest( '.bcp' ) ) {
				return;
			}
			if ( String( form.getAttribute( 'method' ) || 'get' ).toLowerCase() !== 'post' ) {
				return;
			}
			var btn = e.submitter || form.querySelector( '[type="submit"]' );
			if ( btn && btn.classList.contains( 'ajax_add_to_cart' ) ) {
				return; // Řeší WooCommerce AJAX → událost added_to_cart.
			}
			var fd;
			try {
				fd = new FormData( form, e.submitter || undefined );
			} catch ( err ) {
				fd = new FormData( form );
			}
			if ( ! fd.has( 'add-to-cart' ) && btn && btn.name === 'add-to-cart' ) {
				fd.append( 'add-to-cart', btn.value );
			}
			if ( ! /^\d+$/.test( String( fd.get( 'add-to-cart' ) || '' ) ) ) {
				return; // Neznámý formulář → necháme standardní odeslání.
			}
			e.preventDefault();
			e.stopImmediatePropagation();
			if ( ! busy ) {
				addToCart( fd, btn );
			}
		},
		true
	);

	// Výpisy produktů – standardní AJAX tlačítko WooCommerce.
	function bindJQuery() {
		if ( ! window.jQuery || bindJQuery.done ) {
			return;
		}
		bindJQuery.done = true;
		window.jQuery( document.body ).on( 'added_to_cart', function ( e, fragments, hash, $btn ) {
			if ( selfTrigger || ! cfg.loop || ! enabled() ) {
				return;
			}
			var pid = $btn && $btn.length ? String( $btn.attr( 'data-product_id' ) || '' ) : '';
			if ( ! /^\d+$/.test( pid ) ) {
				return;
			}
			var fd = new FormData();
			fd.append( 'product_id', pid );
			post( 'bcp_popup', fd )
				.then( function ( res ) {
					if ( res && res.ok ) {
						open( res );
					}
				} )
				.catch( function () {} );
		} );
	}
	bindJQuery();
	document.addEventListener( 'DOMContentLoaded', bindJQuery );
	window.addEventListener( 'load', bindJQuery );

	/* ---------- Změna množství s okamžitým přepočtem ---------- */
	function scheduleUpdate( li, qty ) {
		var key = li.getAttribute( 'data-key' ) || '';
		if ( ! /^[a-f0-9]{32}$/.test( key ) ) {
			return;
		}
		var unit = parseFloat( li.getAttribute( 'data-unit' ) );
		var line = li.querySelector( '.bcp-line' );
		if ( line && ! isNaN( unit ) ) {
			line.textContent = formatPrice( unit * qty ); // Okamžitě; server za chvíli potvrdí.
		}
		li.classList.add( 'is-busy' );
		clearTimeout( timers[ key ] );
		timers[ key ] = setTimeout( function () {
			sendUpdate( key, qty );
		}, qty === 0 ? 0 : 400 );
	}

	function sendUpdate( key, qty ) {
		var mySeq = ++seq;
		var active = document.activeElement;
		var focusSel = null;
		if ( active && root.contains( active ) && active.closest( '[data-key="' + key + '"]' ) ) {
			focusSel = active.classList.contains( 'bcp-qty__input' ) ? '.bcp-qty__input' : ( active.getAttribute( 'data-step' ) === '1' ? '[data-step="1"]' : ( active.getAttribute( 'data-step' ) === '-1' ? '[data-step="-1"]' : null ) );
		}
		var fd = new FormData();
		fd.append( 'key', key );
		fd.append( 'qty', String( qty ) );
		post( 'bcp_update', fd )
			.then( function ( res ) {
				if ( mySeq !== seq ) {
					return; // Mezitím přišla novější změna.
				}
				if ( res && typeof res.html === 'string' ) {
					body.innerHTML = res.html;
					applyFragments( res.fragments, res.cart_hash );
					if ( focusSel ) {
						var el = body.querySelector( '[data-key="' + key + '"] ' + focusSel );
						if ( el ) {
							el.focus( { preventScroll: true } );
						}
					}
				} else {
					showError( res && res.message );
				}
			} )
			.catch( function () {
				if ( mySeq === seq ) {
					showError();
				}
			} );
	}

	function clampQty( input, value ) {
		var max = parseInt( input.getAttribute( 'max' ), 10 );
		var v = parseInt( value, 10 );
		if ( isNaN( v ) || v < 0 ) {
			v = 0;
		}
		if ( ! isNaN( max ) && max > 0 && v > max ) {
			v = max;
		}
		return Math.min( v, 9999 );
	}

	function onClick( e ) {
		var t = e.target;
		if ( ! ( t instanceof Element ) ) {
			return;
		}
		if ( t.closest( '[data-bcp-close]' ) ) {
			e.preventDefault();
			close();
			return;
		}
		var li = t.closest( '.bcp-item' );
		var step = t.closest( '.bcp-qty__btn' );
		if ( step && li ) {
			var input = li.querySelector( '.bcp-qty__input' );
			if ( input ) {
				var next = clampQty( input, ( parseInt( input.value, 10 ) || 0 ) + parseInt( step.getAttribute( 'data-step' ), 10 ) );
				input.value = String( next );
				scheduleUpdate( li, next );
			}
			return;
		}
		if ( t.closest( '.bcp-remove' ) && li ) {
			li.classList.add( 'is-removing' );
			scheduleUpdate( li, 0 );
			return;
		}
		var rel = t.closest( 'button.bcp-rel__add[data-product]' );
		if ( rel ) {
			var pid = String( rel.getAttribute( 'data-product' ) || '' );
			if ( /^\d+$/.test( pid ) && ! busy ) {
				var fd = new FormData();
				fd.append( 'add-to-cart', pid );
				fd.append( 'quantity', '1' );
				addToCart( fd, rel );
			}
		}
	}

	function onInput( e ) {
		var input = e.target;
		if ( ! ( input instanceof HTMLInputElement ) || ! input.classList.contains( 'bcp-qty__input' ) ) {
			return;
		}
		if ( e.type === 'input' && input.value === '' ) {
			return; // Uživatel teprve píše.
		}
		var li = input.closest( '.bcp-item' );
		if ( li ) {
			scheduleUpdate( li, clampQty( input, input.value ) );
		}
	}

	function onKey( e ) {
		if ( ! root || root.hidden ) {
			return;
		}
		if ( e.key === 'Escape' ) {
			close();
			return;
		}
		if ( e.key === 'Tab' ) {
			var f = dialog.querySelectorAll( 'a[href],button:not([disabled]),input:not([disabled]),[tabindex]:not([tabindex="-1"])' );
			if ( ! f.length ) {
				return;
			}
			var first = f[ 0 ];
			var last = f[ f.length - 1 ];
			if ( e.shiftKey && ( document.activeElement === first || document.activeElement === dialog ) ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		}
	}
} )();
