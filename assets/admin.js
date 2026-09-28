/* global WPMIG */
( function () {
	'use strict';

	var wizard = document.getElementById( 'wpmig-wizard' );
	if ( ! wizard ) {
		return;
	}
	var form = document.getElementById( 'wpmig-form' );
	var current = null;
	var retries = 0;

	function $( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}
	function esc( s ) {
		return String( s === null || s === undefined ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', WPMIG.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			body.append( k, data[ k ] );
		} );
		return fetch( WPMIG.ajax, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( r ) {
			return r.text().then( function ( text ) {
				var json;
				try {
					json = JSON.parse( text );
				} catch ( e ) {
					var err = new Error( 'Réponse invalide du serveur (HTTP ' + r.status + ') : ' + text.replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).trim().slice( 0, 300 ) );
					err.retry = true;
					throw err;
				}
				if ( ! json.success ) {
					throw new Error( ( json.data && json.data.message ) || 'Erreur inconnue.' );
				}
				return json.data;
			} );
		} );
	}

	function showStep( n ) {
		wizard.hidden = false;
		wizard.querySelectorAll( '.wpmig-panel' ).forEach( function ( p ) {
			p.hidden = +p.getAttribute( 'data-step' ) !== n;
		} );
		wizard.querySelectorAll( '.wpmig-steps li' ).forEach( function ( li ) {
			var s = +li.getAttribute( 'data-step' );
			li.className = s === n ? 'active' : s < n ? 'done' : '';
		} );
		error( '' );
	}

	function error( message ) {
		var box = $( '.wpmig-error', wizard );
		box.hidden = ! message;
		box.innerHTML = message ? '<div class="notice notice-error inline"><p>' + esc( message ) + '</p></div>' : '';
	}

	function progress( panel, state ) {
		$( '.wpmig-bar span', panel ).style.width = state.progress + '%';
		$( '.wpmig-msg', panel ).textContent = state.message + ' (' + state.progress + ' %)';
	}

	function collectOptions() {
		var opts = {};
		var els = form.elements;
		for ( var i = 0; i < els.length; i++ ) {
			var el = els[ i ];
			if ( ! el.name ) {
				continue;
			}
			if ( el.name === 'exclude_tables[]' ) {
				opts.exclude_tables = opts.exclude_tables || [];
				if ( el.checked ) {
					opts.exclude_tables.push( el.value );
				}
			} else if ( el.type === 'checkbox' ) {
				opts[ el.name ] = el.checked;
			} else if ( el.type === 'radio' ) {
				if ( el.checked ) {
					opts[ el.name ] = el.value === '1';
				}
			} else {
				opts[ el.name ] = el.value;
			}
		}
		return opts;
	}

	function toggleFilesOptions() {
		var dbOnly = form.querySelector( 'input[name="db_only"]:checked' ).value === '1';
		form.querySelectorAll( '.wpmig-files-only' ).forEach( function ( row ) {
			row.style.display = dbOnly ? 'none' : '';
		} );
	}

	/* Loop on the server until the stage is over. */
	function loop( state ) {
		current = state;
		var panel = wizard.querySelector( '.wpmig-panel[data-step="' + ( state.status === 'scanning' || state.status === 'scanned' ? 2 : 3 ) + '"]' );
		progress( panel, state );
		if ( state.status === 'error' ) {
			error( state.error );
			return;
		}
		if ( state.status === 'scanned' ) {
			renderReport( state );
			return;
		}
		if ( state.status === 'complete' ) {
			renderResult( state );
			return;
		}
		setTimeout( function () {
			post( 'wpmig_step', { id: state.id } ).then( function ( next ) {
				retries = 0;
				loop( next );
			} ).catch( onError );
		}, state.busy ? 3000 : 0 );

		function onError( e ) {
			retries++;
			if ( retries <= 5 ) {
				$( '.wpmig-msg', panel ).textContent = 'Problème temporaire (' + e.message + '). Nouvelle tentative ' + retries + '/5…';
				setTimeout( function () {
					loop( current );
				}, 2000 * retries );
				return;
			}
			error( e.message );
		}
	}

	function checkRow( c ) {
		var labels = { ok: 'OK', warning: 'Attention', error: 'Erreur' };
		return '<tr><th>' + esc( c.label ) + '</th><td><span class="wpmig-badge wpmig-' + esc( c.status ) + '">' + labels[ c.status ] + '</span> ' + esc( c.value ) + '</td></tr>';
	}

	function renderReport( state ) {
		var r = state.report;
		var panel = wizard.querySelector( '.wpmig-panel[data-step="2"]' );
		var html = '';
		var blocking = false;

		html += '<h3>Serveur</h3><table class="widefat striped wpmig-checks"><tbody>';
		r.checks.forEach( function ( c ) {
			blocking = blocking || c.status === 'error';
			html += checkRow( c );
		} );
		html += '</tbody></table>';

		if ( ! r.files.db_only ) {
			html += '<h3>Fichiers</h3><p><strong>' + esc( r.files.count ) + '</strong> fichiers dans <strong>' + esc( r.files.dirs ) + '</strong> dossiers — <strong>' + esc( r.files.size ) + '</strong></p>';
			if ( r.files.large.length ) {
				html += '<details><summary>Fichiers volumineux (' + r.files.large.length + ')</summary><ul>';
				r.files.large.forEach( function ( f ) {
					html += '<li><code>' + esc( f.path ) + '</code> — ' + esc( f.size ) + '</li>';
				} );
				html += '</ul><p class="description">Vous pouvez les exclure (étape 1) et les transférer séparément.</p></details>';
			}
			if ( r.files.excluded.length ) {
				html += '<details><summary>Éléments exclus (' + r.files.excluded.length + ')</summary><ul>';
				r.files.excluded.forEach( function ( f ) {
					html += '<li><code>' + esc( f ) + '</code></li>';
				} );
				html += '</ul></details>';
			}
		}

		html += '<h3>Base de données</h3><p><strong>' + esc( r.db.count ) + '</strong> tables — ~' + esc( r.db.rows ) + ' lignes — ' + esc( r.db.size ) + '</p>';
		html += '<details><summary>Détail des tables</summary><table class="widefat striped"><thead><tr><th>Table</th><th>Lignes</th><th>Taille</th></tr></thead><tbody>';
		r.db.tables.forEach( function ( t ) {
			html += '<tr><td><code>' + esc( t.name ) + '</code>' + ( t.warn ? '<br><span class="wpmig-warning-text">' + esc( t.warn ) + '</span>' : '' ) + '</td><td>' + esc( t.rows ) + '</td><td>' + esc( t.size ) + '</td></tr>';
		} );
		html += '</tbody></table></details>';

		if ( state.warnings.length ) {
			html += '<h3>Avertissements</h3><ul class="wpmig-warnings">';
			state.warnings.forEach( function ( w ) {
				html += '<li>' + esc( w ) + '</li>';
			} );
			html += '</ul>';
		}
		if ( blocking ) {
			html += '<div class="notice notice-error inline"><p>Des erreurs bloquantes empêchent la construction du package.</p></div>';
		}
		$( '.wpmig-report', panel ).innerHTML = html;
		$( '.wpmig-progress', panel ).hidden = true;
		var actions = $( '.wpmig-actions', panel );
		actions.hidden = false;
		$( '[data-action="build"]', actions ).disabled = blocking;
	}

	function downloadUrl( id, file ) {
		return WPMIG.download + '&id=' + encodeURIComponent( id ) + '&file=' + file;
	}

	function renderResult( state ) {
		var panel = wizard.querySelector( '.wpmig-panel[data-step="3"]' );
		var html = '<div class="notice notice-success inline"><p><strong>Package prêt !</strong> Téléchargez les deux fichiers et déposez-les dans le dossier du nouveau site.</p></div>';
		html += '<p class="wpmig-downloads">';
		html += '<a class="button button-primary button-hero" href="' + esc( downloadUrl( state.id, 'archive' ) ) + '">Archive (' + esc( state.sizes.archive_h || '' ) + ')</a> ';
		html += '<a class="button button-primary button-hero" href="' + esc( downloadUrl( state.id, 'installer' ) ) + '">installer.php</a>';
		html += '</p>';
		if ( state.warnings.length ) {
			html += '<details><summary>Avertissements (' + state.warnings.length + ')</summary><ul class="wpmig-warnings">';
			state.warnings.forEach( function ( w ) {
				html += '<li>' + esc( w ) + '</li>';
			} );
			html += '</ul></details>';
		}
		html += '<p><a class="button" href="">Retour à la liste des packages</a></p>';
		$( '.wpmig-result', panel ).innerHTML = html;
		$( '.wpmig-progress', panel ).hidden = true;
	}

	/* Events. */
	document.getElementById( 'wpmig-new' ).addEventListener( 'click', function () {
		form.reset();
		toggleFilesOptions();
		showStep( 1 );
		wizard.scrollIntoView( { behavior: 'smooth' } );
	} );

	form.addEventListener( 'change', function ( e ) {
		if ( e.target.name === 'db_only' ) {
			toggleFilesOptions();
		}
	} );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		showStep( 2 );
		var panel = wizard.querySelector( '.wpmig-panel[data-step="2"]' );
		$( '.wpmig-progress', panel ).hidden = false;
		$( '.wpmig-actions', panel ).hidden = true;
		$( '.wpmig-report', panel ).innerHTML = '';
		progress( panel, { progress: 0, message: 'Démarrage de l\'analyse…' } );
		post( 'wpmig_create', { options: JSON.stringify( collectOptions() ) } ).then( loop ).catch( function ( err ) {
			error( err.message );
		} );
	} );

	wizard.addEventListener( 'click', function ( e ) {
		var action = e.target.getAttribute( 'data-action' );
		if ( ! action ) {
			return;
		}
		if ( action === 'cancel' ) {
			wizard.hidden = true;
		} else if ( action === 'discard' ) {
			if ( current ) {
				post( 'wpmig_delete', { id: current.id } );
			}
			showStep( 1 );
		} else if ( action === 'build' ) {
			e.target.disabled = true;
			showStep( 3 );
			var panel = wizard.querySelector( '.wpmig-panel[data-step="3"]' );
			$( '.wpmig-progress', panel ).hidden = false;
			$( '.wpmig-result', panel ).innerHTML = '';
			progress( panel, { progress: 0, message: 'Démarrage…' } );
			post( 'wpmig_build', { id: current.id } ).then( loop ).catch( function ( err ) {
				error( err.message );
			} );
		}
	} );

	/* Package list actions. */
	var list = document.querySelector( '.wpmig-packages' );
	if ( list ) {
		list.addEventListener( 'click', function ( e ) {
			var target = e.target;
			var row = target.closest( 'tr[data-id]' );
			if ( ! row ) {
				return;
			}
			var id = row.getAttribute( 'data-id' );
			var dl = target.getAttribute( 'data-download' );
			if ( dl ) {
				e.preventDefault();
				if ( dl === 'both' ) {
					window.location.href = downloadUrl( id, 'archive' );
					setTimeout( function () {
						window.location.href = downloadUrl( id, 'installer' );
					}, 1500 );
				} else {
					window.location.href = downloadUrl( id, dl );
				}
				return;
			}
			if ( target.getAttribute( 'data-delete' ) ) {
				if ( ! window.confirm( 'Supprimer définitivement ce package ?' ) ) {
					return;
				}
				target.disabled = true;
				post( 'wpmig_delete', { id: id } ).then( function () {
					row.parentNode.removeChild( row );
				} ).catch( function ( err ) {
					target.disabled = false;
					window.alert( err.message );
				} );
				return;
			}
			if ( target.getAttribute( 'data-resume' ) ) {
				target.disabled = true;
				post( 'wpmig_step', { id: id } ).then( function ( state ) {
					showStep( state.status === 'scanning' || state.status === 'scanned' ? 2 : 3 );
					wizard.scrollIntoView( { behavior: 'smooth' } );
					loop( state );
				} ).catch( function ( err ) {
					target.disabled = false;
					window.alert( err.message );
				} );
			}
		} );
	}
}() );
