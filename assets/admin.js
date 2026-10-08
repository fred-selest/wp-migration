/* global WPMIG */
( function () {
	'use strict';

	var wizard = document.getElementById( 'wpmig-wizard' );
	var form = document.getElementById( 'wpmig-form' );
	var current = null;
	var retries = 0;
	var password = '';

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

	/* Installer password: 16 random characters without ambiguous ones (0/O, 1/l/I). */
	function generatePassword() {
		var chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		var bytes = new Uint32Array( 16 );
		var out = '';
		window.crypto.getRandomValues( bytes );
		for ( var i = 0; i < bytes.length; i++ ) {
			out += chars.charAt( bytes[ i ] % chars.length );
			if ( i === 3 || i === 7 || i === 11 ) {
				out += '-';
			}
		}
		return out;
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
			html += '<tr><td><code>' + esc( t.name ) + '</code>' + ( t.warn ? '<br><span class="wpmig-warning-text">' + esc( t.warn ) + '</span>' : '' ) + ( t.empty ? '<br><span class="description">Données exclues : table recréée vide</span>' : '' ) + '</td><td>' + ( t.empty ? '—' : esc( t.rows ) ) + '</td><td>' + ( t.empty ? '—' : esc( t.size ) ) + '</td></tr>';
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
			html += '<div class="notice notice-error inline"><p>Des erreurs bloquantes empêchent la création de la sauvegarde.</p></div>';
		}
		$( '.wpmig-report', panel ).innerHTML = html;
		$( '.wpmig-progress', panel ).hidden = true;
		var actions = $( '.wpmig-actions', panel );
		actions.hidden = false;
		$( '[data-action="build"]', actions ).disabled = blocking;
	}

	/* Direct server to server transfer panel. */
	function transferPanel( id, container ) {
		container.innerHTML = '<p class="description">Création du lien…</p>';
		return post( 'wpmig_transfer_link', { id: id } ).then( function ( link ) {
			var html = '<div class="wpmig-transfer">';
			html += '<h3>Transfert direct de serveur à serveur</h3>';
			if ( link.insecure ) {
				html += '<div class="notice notice-warning inline"><p>Ce site n\'est pas en HTTPS : l\'archive transitera en clair.</p></div>';
			}
			html += '<p>Déposez seulement <code>installer.php</code> sur le nouveau serveur et ouvrez-le : il propose de récupérer l\'archive depuis ce site. Collez-y ce lien secret, valable jusqu\'au <strong>' + esc( link.expires_h ) + '</strong> :</p>';
			html += '<p class="wpmig-copy"><input type="text" class="large-text code" readonly value="' + esc( link.url ) + '"> <button type="button" class="button" data-copy="1">Copier</button></p>';
			html += '<details><summary>En SSH</summary><pre class="wpmig-pre">curl -o installer.php \'' + esc( link.installer_url ) + '\'\nphp installer.php --source-url=\'' + esc( link.url ) + '\' \\\n  --url=https://nouveau-site.fr --db-name=base --db-user=utilisateur --db-pass=secret</pre></details>';
			html += '<p class="description">Toute personne possédant ce lien peut télécharger une copie complète du site : ne le partagez pas et révoquez-le une fois la migration terminée. Créer un nouveau lien invalide le précédent.</p>';
			html += '<p><button type="button" class="button button-link-delete" data-revoke="' + esc( id ) + '">Révoquer le lien</button></p>';
			html += '</div>';
			container.innerHTML = html;
		} ).catch( function ( err ) {
			container.innerHTML = '<div class="notice notice-error inline"><p>' + esc( err.message ) + '</p></div>';
		} );
	}

	/* Copy / revoke buttons of the transfer panels. */
	document.addEventListener( 'click', function ( e ) {
		var target = e.target;
		if ( target.getAttribute( 'data-copy' ) ) {
			var input = target.parentNode.querySelector( 'input' );
			input.select();
			var done = function () {
				target.textContent = 'Copié !';
				setTimeout( function () {
					target.textContent = 'Copier';
				}, 2000 );
			};
			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( input.value ).then( done );
			} else {
				document.execCommand( 'copy' );
				done();
			}
		} else if ( target.getAttribute( 'data-revoke' ) ) {
			target.disabled = true;
			post( 'wpmig_transfer_revoke', { id: target.getAttribute( 'data-revoke' ) } ).then( function () {
				target.closest( '.wpmig-transfer' ).innerHTML = '<p>Lien révoqué : il ne permet plus aucun téléchargement.</p>';
			} ).catch( function ( err ) {
				target.disabled = false;
				window.alert( err.message );
			} );
		}
	} );

	function downloadUrl( id, file ) {
		return WPMIG.download + '&id=' + encodeURIComponent( id ) + '&file=' + file;
	}

	function renderResult( state ) {
		var panel = wizard.querySelector( '.wpmig-panel[data-step="3"]' );
		var html = '<div class="notice notice-success inline"><p><strong>Sauvegarde prête !</strong> Téléchargez les deux fichiers et déposez-les dans le dossier du nouveau site.</p></div>';
		html += '<p class="wpmig-downloads">';
		html += '<a class="button button-primary button-hero" href="' + esc( downloadUrl( state.id, 'archive' ) ) + '">Archive (' + esc( state.sizes.archive_h || '' ) + ')</a> ';
		html += '<a class="button button-primary button-hero" href="' + esc( downloadUrl( state.id, 'installer' ) ) + '">installer.php</a>';
		html += '</p>';
		if ( password && state.secured ) {
			html += '<p>Mot de passe de l\'installeur : <code class="wpmig-password">' + esc( password ) + '</code> — notez-le, il ne sera plus affiché.</p>';
		} else if ( state.secured ) {
			html += '<p>Installeur protégé par le mot de passe défini à la création de la sauvegarde.</p>';
		} else {
			html += '<div class="notice notice-warning inline"><p>Installeur <strong>sans mot de passe</strong> : ne le laissez pas en ligne sans surveillance.</p></div>';
		}
		if ( state.warnings.length ) {
			html += '<details><summary>Avertissements (' + state.warnings.length + ')</summary><ul class="wpmig-warnings">';
			state.warnings.forEach( function ( w ) {
				html += '<li>' + esc( w ) + '</li>';
			} );
			html += '</ul></details>';
		}
		html += '<p><button type="button" class="button" id="wpmig-result-transfer">Transfert direct de serveur à serveur</button> <a class="button" href="">Retour à la liste des sauvegardes</a></p>';
		html += '<div id="wpmig-result-transfer-panel"></div>';
		$( '.wpmig-result', panel ).innerHTML = html;
		$( '#wpmig-result-transfer', panel ).addEventListener( 'click', function () {
			this.disabled = true;
			transferPanel( state.id, $( '#wpmig-result-transfer-panel', panel ) );
		} );
		$( '.wpmig-progress', panel ).hidden = true;
	}

	/* Backup created in one click: the whole site, or the database only (also used before the tools that change it). */
	function backupDb( box, name, opts ) {
		opts = opts || { dbOnly: true };
		var pass = generatePassword();
		var failures = 0;
		box.innerHTML = '<div class="wpmig-bar"><span style="width:3%"></span></div><p class="wpmig-msg">Sauvegarde de la base de données…</p>';
		var show = function ( state ) {
			$( 'span', box ).style.width = Math.max( 3, state.progress || 0 ) + '%';
			$( '.wpmig-msg', box ).textContent = state.message || '';
		};
		var later = function ( action, id ) {
			return new Promise( function ( resolve ) {
				setTimeout( resolve, 200 );
			} ).then( function () {
				return post( action, { id: id } ).catch( function ( err ) {
					if ( err.retry && failures++ < 5 ) {
						return later( action, id );
					}
					throw err;
				} );
			} );
		};
		var run = function ( state ) {
			failures = 0;
			show( state );
			if ( state.status === 'error' ) {
				throw new Error( state.error );
			}
			if ( state.status === 'scanned' ) {
				var blocking = ( state.report && state.report.checks ? state.report.checks : [] ).filter( function ( c ) {
					return c.status === 'error';
				} );
				if ( blocking.length ) {
					post( 'wpmig_delete', { id: state.id } );
					throw new Error( blocking.map( function ( c ) {
						return c.label + ' : ' + c.value;
					} ).join( ' ; ' ) + ' — utilisez « Personnaliser » pour le détail.' );
				}
				return later( 'wpmig_build', state.id ).then( run );
			}
			if ( state.status === 'complete' ) {
				return state;
			}
			return later( 'wpmig_step', state.id ).then( run );
		};
		// A database backup keeps everything: nothing is skipped. A whole site keeps the usual defaults.
		var options = opts.dbOnly ? { name: name, db_only: true, skip_transients: false, skip_spam: false, skip_revisions: false, password: pass } : { name: name, password: pass };
		return post( 'wpmig_create', { options: JSON.stringify( options ) } ).then( run ).then( function ( state ) {
			var what = opts.dbOnly ? 'Téléchargez les deux fichiers et gardez-les avec le mot de passe : ils permettent de rétablir la base avec l\'installeur.' : 'Téléchargez les deux fichiers et déposez-les dans le dossier du nouveau site, puis ouvrez installer.php.';
			var html = '<div class="notice notice-success inline"><p><strong>Sauvegarde prête</strong> (' + esc( state.sizes.archive_h || '' ) + '). ' + what + ' Elle figure aussi dans la liste des sauvegardes.</p></div>';
			html += '<p class="wpmig-downloads"><a class="button button-primary" href="' + esc( downloadUrl( state.id, 'archive' ) ) + '">Archive (' + esc( state.sizes.archive_h || '' ) + ')</a> <a class="button button-primary" href="' + esc( downloadUrl( state.id, 'installer' ) ) + '">installer.php</a>';
			if ( opts.transfer ) {
				html += ' <button type="button" class="button" data-backup-transfer="' + esc( state.id ) + '">Transfert direct de serveur à serveur</button> <a class="button" href="">Afficher la liste des sauvegardes</a>';
			}
			html += '</p>';
			html += '<p>Mot de passe de l\'installeur : <code class="wpmig-password">' + esc( pass ) + '</code> — notez-le, il ne sera plus affiché.</p>';
			if ( opts.transfer ) {
				html += '<div class="wpmig-backup-transfer"></div>';
			}
			box.innerHTML = html;
			return state;
		} ).catch( function ( err ) {
			box.innerHTML = '<div class="notice notice-error inline"><p>Sauvegarde impossible : ' + esc( err.message ) + '</p></div>';
			throw err;
		} );
	}

	/* One-click backups (Sauvegardes tab). */
	var quick = document.getElementById( 'wpmig-quick' );
	if ( quick ) {
		quick.addEventListener( 'click', function ( e ) {
			var choice = e.target.closest( '[data-quick]' );
			var transfer = e.target.getAttribute( 'data-backup-transfer' );
			if ( transfer ) {
				e.target.disabled = true;
				transferPanel( transfer, $( '.wpmig-backup-transfer', quick ) );
				return;
			}
			if ( ! choice ) {
				return;
			}
			var full = choice.getAttribute( 'data-quick' ) === 'full';
			quick.querySelectorAll( '[data-quick]' ).forEach( function ( b ) {
				b.disabled = true;
			} );
			backupDb( $( '#wpmig-quick-box', quick ), full ? '' : 'base-de-donnees', { dbOnly: ! full, transfer: true } ).catch( function () {} ).then( function () {
				quick.querySelectorAll( '[data-quick]' ).forEach( function ( b ) {
					b.disabled = false;
				} );
			} );
		} );
	}

	/* Events. */
	if ( wizard ) {
		document.getElementById( 'wpmig-new' ).addEventListener( 'click', function () {
			form.reset();
			form.elements.password.value = generatePassword();
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
			var options = collectOptions();
			password = options.password || '';
			if ( ! password && ! window.confirm( 'Créer un installeur sans mot de passe ? C\'est déconseillé : toute personne trouvant installer.php en ligne pourrait l\'utiliser.' ) ) {
				showStep( 1 );
				return;
			}
			post( 'wpmig_create', { options: JSON.stringify( options ) } ).then( loop ).catch( function ( err ) {
				error( err.message );
			} );
		} );

		wizard.addEventListener( 'click', function ( e ) {
			var action = e.target.getAttribute( 'data-action' );
			if ( ! action ) {
				return;
			}
			if ( action === 'genpass' ) {
				form.elements.password.value = generatePassword();
			} else if ( action === 'cancel' ) {
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
	}

	/* Import from another site. */
	var importForm = document.getElementById( 'wpmig-import-form' );
	if ( importForm ) {
		importForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var box = document.getElementById( 'wpmig-import-msg' );
			var button = importForm.querySelector( 'button' );
			if ( ! window.confirm( 'Ce site va être entièrement remplacé par le site d\'origine (contenus, réglages, extensions, comptes). Continuer ?' ) ) {
				return;
			}
			button.disabled = true;
			box.innerHTML = '<p class="description">Récupération de l\'installeur…</p>';
			post( 'wpmig_import_prepare', { link: document.getElementById( 'wpmig-import-link' ).value } ).then( function ( res ) {
				window.location.href = res.url;
			} ).catch( function ( err ) {
				button.disabled = false;
				box.innerHTML = '<div class="notice notice-error inline"><p>' + esc( err.message ) + '</p></div>';
			} );
		} );
	}

	/* Backup list actions. */
	var list = document.querySelector( '.wpmig-packages' );
	if ( list ) {
		list.addEventListener( 'click', function ( e ) {
			var target = e.target;
			if ( target.getAttribute( 'data-restore-cancel' ) ) {
				var holder = target.closest( 'tr' );
				var owner = holder.hasAttribute( 'data-id' ) ? holder : holder.previousElementSibling;
				target.disabled = true;
				post( 'wpmig_restore_cancel', { id: owner.getAttribute( 'data-id' ) } ).then( function () {
					window.location.reload();
				} ).catch( function ( err ) {
					target.disabled = false;
					window.alert( err.message );
				} );
				return;
			}
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
			if ( target.getAttribute( 'data-transfer' ) ) {
				var existing = row.nextElementSibling;
				if ( existing && existing.classList.contains( 'wpmig-transfer-row' ) ) {
					existing.parentNode.removeChild( existing );
					return;
				}
				var tr = document.createElement( 'tr' );
				tr.className = 'wpmig-transfer-row';
				tr.innerHTML = '<td colspan="5"></td>';
				row.parentNode.insertBefore( tr, row.nextSibling );
				transferPanel( id, tr.firstChild );
				return;
			}
			if ( target.getAttribute( 'data-restore' ) ) {
				if ( ! window.confirm( 'Restaurer cette sauvegarde ?\n\nTous les fichiers, la base de données et les comptes de ce site seront REMPLACÉS par ceux de la sauvegarde. Conseil : créez d\'abord une sauvegarde de l\'état actuel.\n\nL\'installeur de la sauvegarde va être préparé ; rien n\'est modifié tant que vous ne le lancez pas.' ) ) {
					return;
				}
				target.disabled = true;
				post( 'wpmig_restore_prepare', { id: id } ).then( function ( res ) {
					var tr = document.createElement( 'tr' );
					tr.className = 'wpmig-transfer-row';
					tr.innerHTML = '<td colspan="5"><div class="notice notice-warning inline"><p><strong>Restauration préparée.</strong> Ouvrez l\'installeur, saisissez le mot de passe de la sauvegarde (celui choisi à sa création) : les accès à la base de données de ce site sont repris. ' + ( res.mode === 'link' ? 'L\'archive n\'a pas été dupliquée.' : 'L\'archive a été copiée à côté de l\'installeur.' ) + '</p><p><a class="button button-primary" href="' + esc( res.url ) + '">Ouvrir l\'installeur</a> <button type="button" class="button" data-restore-cancel="1">Annuler la préparation</button></p></div></td>';
					row.parentNode.insertBefore( tr, row.nextSibling );
					target.disabled = false;
				} ).catch( function ( err ) {
					target.disabled = false;
					window.alert( err.message );
				} );
				return;
			}
			if ( target.getAttribute( 'data-delete' ) ) {
				if ( ! window.confirm( 'Supprimer définitivement cette sauvegarde ?' ) ) {
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

	/* Content synchronization, source side: link. */
	var syncSource = document.getElementById( 'wpmig-sync-source' );
	if ( syncSource ) {
		syncSource.addEventListener( 'click', function ( e ) {
			var target = e.target;
			if ( target.hasAttribute( 'data-sync-link' ) ) {
				target.disabled = true;
				post( 'wpmig_sync_link', { hours: $( '#wpmig-sync-hours' ).value } ).then( function ( link ) {
					target.disabled = false;
					var html = '<div class="wpmig-transfer"><h3>Lien de synchronisation</h3>';
					html += '<p>Valable jusqu\'au ' + esc( link.expires_h ) + '. Collez-le sur la copie de travail : <strong>WP Migration → Synchronisation</strong>. Il ne sera plus affiché : copiez-le maintenant (un nouveau lien remplace le précédent).</p>';
					html += '<div class="wpmig-copy"><input type="text" class="large-text code" readonly value="' + esc( link.url ) + '"><button type="button" class="button" data-copy="1">Copier</button></div>';
					if ( link.insecure ) {
						html += '<p class="wpmig-warning-text">Ce site n\'est pas en HTTPS : les données transiteront en clair.</p>';
					}
					html += '<details><summary>En SSH (WP-CLI)</summary><pre class="wpmig-pre">wp migration sync \'' + esc( link.url ) + '\' --dry-run</pre></details></div>';
					$( '#wpmig-sync-link-panel' ).innerHTML = html;
					$( '.wpmig-sync-link-status', syncSource ).innerHTML = '<p><span class="wpmig-transfer-active">● Lien actif jusqu\'au ' + esc( link.expires_h ) + '</span></p>';
				} ).catch( function ( err ) {
					target.disabled = false;
					window.alert( err.message );
				} );
			}
			if ( target.hasAttribute( 'data-sync-revoke' ) ) {
				post( 'wpmig_sync_revoke' ).then( function () {
					$( '.wpmig-sync-link-status', syncSource ).innerHTML = '<p>Lien révoqué : il ne permet plus aucune lecture.</p>';
					$( '#wpmig-sync-link-panel' ).innerHTML = '';
					target.parentNode.removeChild( target );
				} );
			}
		} );
	}

	/* Content synchronization, destination side. */
	var sync = document.getElementById( 'wpmig-sync' );
	if ( sync ) {
		var syncForm = $( '#wpmig-sync-form' );
		var syncPanel = $( '#wpmig-sync-panel' );
		var syncLabels = JSON.parse( sync.getAttribute( 'data-labels' ) );
		var syncRetries = 0;
		var running = [ 'analyzing', 'importing', 'files', 'finalizing', 'undoing' ];

		var countsTable = function ( state ) {
			var order = [ 'insert', 'update', 'stock', 'keep', 'same', 'skip' ];
			var rows = '';
			Object.keys( state.counts || {} ).forEach( function ( kind ) {
				var c = state.counts[ kind ];
				rows += '<tr><th>' + esc( syncLabels.kinds[ kind ] || kind ) + '</th>';
				order.forEach( function ( a ) {
					rows += '<td class="num">' + ( c[ a ] ? esc( c[ a ] ) : '<span class="description">–</span>' ) + '</td>';
				} );
				rows += '</tr>';
			} );
			if ( ! rows ) {
				return '<p>Aucun contenu créé ou modifié sur le site d\'origine depuis cette date.</p>';
			}
			var labels = state.status === 'done' ? syncLabels.done : syncLabels.actions;
			var head = '<tr><th></th>' + order.map( function ( a ) {
				return '<th class="num">' + esc( labels[ a ] ) + '</th>';
			} ).join( '' ) + '</tr>';
			return '<table class="widefat striped wpmig-sync-counts"><thead>' + head + '</thead><tbody>' + rows + '</tbody></table>';
		};

		var notesList = function ( state ) {
			var html = '';
			if ( state.notes && state.notes.length ) {
				html += '<details open><summary>Points à connaître (' + state.notes.length + ')</summary><ul class="wpmig-report-list">';
				state.notes.forEach( function ( n ) {
					html += '<li>' + esc( n[ 2 ] ) + '</li>';
				} );
				html += '</ul></details>';
			}
			if ( state.warnings && state.warnings.length ) {
				html += '<ul class="wpmig-warnings">';
				state.warnings.forEach( function ( w ) {
					html += '<li>' + esc( w ) + '</li>';
				} );
				html += '</ul>';
			}
			return html;
		};

		var renderSync = function ( state ) {
			var html = '<div class="wpmig-sync-state">';
			html += '<p>Site d\'origine : <code>' + esc( state.source ) + '</code> — contenus créés ou modifiés depuis le <strong>' + esc( state.threshold_h ) + '</strong>' + ( state.force ? ' (contenus modifiés ici remplacés)' : '' ) + '</p>';
			if ( state.status === 'error' ) {
				html += '<div class="notice notice-error inline"><p>' + esc( state.error ) + '</p></div>';
				html += '<p><button type="button" class="button" data-sync="dismiss">Fermer</button></p>';
			} else if ( running.indexOf( state.status ) !== -1 ) {
				html += '<div class="wpmig-bar"><span style="width:' + ( state.status === 'analyzing' ? 5 : Math.max( 5, state.progress ) ) + '%"></span></div>';
				html += '<p class="wpmig-msg">' + esc( state.message ) + '</p>';
			} else if ( state.status === 'ready' ) {
				html += '<h3>Analyse</h3>' + countsTable( state ) + notesList( state );
				html += '<p>' + ( state.lines ? '<button type="button" class="button button-primary" data-sync="confirm">Importer ces contenus</button> <button type="button" class="button" data-sync="backup">Sauvegarder la base de données</button> ' : '' ) + '<button type="button" class="button" data-sync="dismiss">' + ( state.lines ? 'Abandonner' : 'Fermer' ) + '</button></p>';
				if ( state.lines ) {
					html += '<div id="wpmig-sync-backup"></div><p class="description">Conseil : sauvegardez d\'abord la base de données. La synchronisation peut aussi être annulée juste après.</p>';
				}
			} else if ( state.status === 'done' ) {
				html += '<div class="notice notice-success inline"><p><strong>Synchronisation terminée.</strong> ' + ( state.files && state.files.total ? esc( state.files.downloaded + ' fichier(s) de médias téléchargé(s).' ) : '' ) + '</p></div>';
				html += countsTable( state ) + notesList( state );
				html += '<p><button type="button" class="button" data-sync="dismiss">Terminer</button> <button type="button" class="button-link wpmig-danger" data-sync="undo">Annuler cette synchronisation</button></p>';
			} else if ( state.status === 'undone' ) {
				html += '<div class="notice notice-info inline"><p>' + esc( state.message ) + '</p></div>';
				html += '<p><button type="button" class="button" data-sync="dismiss">Fermer</button></p>';
			}
			html += '</div>';
			syncPanel.innerHTML = html;
			syncForm.hidden = true;
		};

		var syncLoop = function ( state ) {
			syncRetries = 0;
			renderSync( state );
			if ( running.indexOf( state.status ) === -1 ) {
				return;
			}
			setTimeout( function () {
				post( 'wpmig_sync_step' ).then( syncLoop ).catch( function ( err ) {
					if ( err.retry && syncRetries++ < 5 ) {
						setTimeout( function () {
							syncLoop( state );
						}, 3000 * syncRetries );
						return;
					}
					syncPanel.insertAdjacentHTML( 'beforeend', '<div class="notice notice-error inline"><p>' + esc( err.message ) + ' Rechargez la page pour reprendre.</p></div>' );
				} );
			}, 300 );
		};

		/* Once the link is pasted: date of the copy and packages of the source. */
		$( '#wpmig-sync-link' ).addEventListener( 'change', function () {
			var help = $( '#wpmig-sync-since-help' );
			var select = $( '#wpmig-sync-packages' );
			post( 'wpmig_sync_probe', { link: this.value } ).then( function ( info ) {
				select.innerHTML = '<option value="">Sauvegarde utilisée pour la copie…</option>';
				info.packages.forEach( function ( p ) {
					select.insertAdjacentHTML( 'beforeend', '<option value="' + esc( p.value ) + '">' + esc( p.label ) + '</option>' );
				} );
				select.hidden = ! info.packages.length;
				if ( info.default ) {
					$( '#wpmig-sync-since' ).value = info.default;
					help.textContent = 'Date trouvée automatiquement (' + info.default_h + ').';
				} else {
					help.textContent = 'Indiquez quand ce site a été copié depuis ' + info.source + ', ou choisissez la sauvegarde utilisée.';
				}
			} ).catch( function ( err ) {
				help.textContent = err.message;
			} );
		} );
		$( '#wpmig-sync-packages' ).addEventListener( 'change', function () {
			if ( this.value ) {
				$( '#wpmig-sync-since' ).value = this.value;
			}
		} );

		syncForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var kinds = [];
			syncForm.querySelectorAll( 'input[name="kinds"]:checked' ).forEach( function ( c ) {
				kinds.push( c.value );
			} );
			var button = $( 'button[type="submit"]', syncForm );
			button.disabled = true;
			post( 'wpmig_sync_start', {
				link: $( '#wpmig-sync-link' ).value,
				kinds: kinds.join( ',' ),
				since: $( '#wpmig-sync-since' ).value,
				force: $( '#wpmig-sync-force' ).checked ? 1 : ''
			} ).then( syncLoop ).catch( function ( err ) {
				button.disabled = false;
				syncPanel.innerHTML = '<div class="notice notice-error inline"><p>' + esc( err.message ) + '</p></div>';
			} );
		} );

		syncPanel.addEventListener( 'click', function ( e ) {
			var action = e.target.getAttribute( 'data-sync' );
			if ( ! action ) {
				return;
			}
			if ( action === 'undo' && ! window.confirm( 'Annuler cette synchronisation ? Les contenus importés sont retirés et les contenus de ce site remis dans leur état précédent.' ) ) {
				return;
			}
			if ( action === 'confirm' && ! window.confirm( 'Importer ces contenus sur ce site ?' ) ) {
				return;
			}
			if ( action === 'backup' ) {
				var backupButton = e.target;
				backupButton.disabled = true;
				backupDb( $( '#wpmig-sync-backup', sync ), 'avant-synchronisation' ).catch( function () {} ).then( function () {
					backupButton.disabled = false;
				} );
				return;
			}
			e.target.disabled = true;
			if ( action === 'dismiss' ) {
				post( 'wpmig_sync_dismiss' ).then( function () {
					window.location.reload();
				} ).catch( function ( err ) {
					e.target.disabled = false;
					window.alert( err.message );
				} );
				return;
			}
			post( 'wpmig_sync_' + action ).then( syncLoop ).catch( function ( err ) {
				e.target.disabled = false;
				window.alert( err.message );
			} );
		} );

		var initial = sync.getAttribute( 'data-state' );
		if ( initial ) {
			syncLoop( JSON.parse( initial ) );
		}
	}
	/* Search and replace in the database. */
	var search = document.getElementById( 'wpmig-search' );
	if ( search ) {
		var searchForm = $( '#wpmig-search-form' );
		var searchPanel = $( '#wpmig-search-panel' );
		var searchRetries = 0;
		var searchRunning = [ 'analyzing', 'applying', 'undoing' ];
		var searchMode = function () {
			return searchForm.querySelector( 'input[name="wpmig-search-mode"]:checked' ).value;
		};

		var searchResults = function ( state ) {
			var html = '';
			if ( ! state.tables.length ) {
				return '<p>Aucune occurrence trouvée.</p>';
			}
			html += '<table class="widefat striped wpmig-search-tables"><thead><tr><th>Table</th><th>Colonnes</th><th class="num">Lignes</th><th class="num">Occurrences</th></tr></thead><tbody>';
			state.tables.forEach( function ( t ) {
				html += '<tr><td><code>' + esc( t.name ) + '</code></td><td>' + esc( t.cols.join( ', ' ) ) + '</td><td class="num">' + esc( t.rows ) + '</td><td class="num">' + esc( t.matches ) + '</td></tr>';
			} );
			html += '</tbody></table>';
			if ( state.samples.length ) {
				html += '<details><summary>Exemples (' + state.samples.length + ')</summary><table class="widefat striped wpmig-search-samples"><thead><tr><th>Où</th><th>Avant / après</th></tr></thead><tbody>';
				state.samples.forEach( function ( x ) {
					html += '<tr><td><code>' + esc( x.table ) + '</code><br>' + esc( x.row ) + '<br>' + esc( x.column ) + '</td><td>' + esc( x.pre ) + '<del>' + esc( x.before ) + '</del>' + esc( x.post ) + '<br>' + esc( x.pre ) + '<ins>' + esc( x.after ) + '</ins>' + esc( x.post ) + '</td></tr>';
				} );
				html += '</tbody></table></details>';
			}
			return html;
		};

		var searchSummary = function ( state, past ) {
			var t = state.totals;
			return '<strong>' + esc( t.matches ) + '</strong> occurrence(s) ' + ( past ? 'remplacée(s)' : 'à remplacer' ) + ' dans <strong>' + esc( t.changed ) + '</strong> ligne(s) de ' + esc( t.tables ) + ' table(s).';
		};

		var renderSearch = function ( state ) {
			var p = state.params;
			var modes = { text: 'texte', url: 'URL / chemin', regex: 'expression régulière' };
			var html = '<div class="wpmig-sync-state">';
			html += '<p>Recherche (' + esc( modes[ p.mode ] ) + ') : <code>' + esc( p.search ) + '</code> → <code>' + ( p.replace === '' ? '<em>(supprimé)</em>' : esc( p.replace ) ) + '</code></p>';
			if ( state.status === 'analyzing' || state.status === 'applying' ) {
				html += '<div class="wpmig-bar"><span style="width:' + Math.max( 3, state.progress ) + '%"></span></div>';
				html += '<p class="wpmig-msg">' + ( state.status === 'analyzing' ? 'Analyse' : 'Remplacement' ) + ' en cours' + ( state.table ? ' : <code>' + esc( state.table ) + '</code>' : '' ) + ' — ' + searchSummary( state, state.status === 'applying' ) + '</p>';
			} else if ( state.status === 'undoing' ) {
				html += '<div class="wpmig-bar"><span style="width:' + Math.max( 3, state.progress ) + '%"></span></div><p class="wpmig-msg">Annulation en cours…</p>';
			} else if ( state.status === 'analyzed' ) {
				( state.warnings || [] ).forEach( function ( w ) {
					html += '<div class="notice notice-warning inline"><p>' + esc( w ) + '</p></div>';
				} );
				html += '<h3>Analyse</h3><p>' + ( state.totals.changed ? searchSummary( state, false ) : 'Aucune occurrence trouvée : rien à remplacer.' ) + '</p>' + searchResults( state );
				if ( state.skipped.length ) {
					html += '<p class="description">Tables ignorées : ' + esc( state.skipped.join( ', ' ) ) + '.</p>';
				}
				html += '<p>' + ( state.totals.changed ? '<button type="button" class="button button-primary" data-search="confirm">Remplacer</button> <button type="button" class="button" data-search="backup">Sauvegarder la base de données</button> ' : '' ) + '<button type="button" class="button" data-search="dismiss">' + ( state.totals.changed ? 'Abandonner' : 'Fermer' ) + '</button></p>';
				if ( state.totals.changed ) {
					html += '<div id="wpmig-search-backup"></div><p class="description">Conseil : sauvegardez d\'abord la base de données. Le remplacement peut aussi être annulé juste après.</p>';
				}
			} else if ( state.status === 'done' ) {
				html += '<div class="notice notice-success inline"><p><strong>Remplacement terminé.</strong> ' + searchSummary( state, true ) + '</p></div>' + searchResults( state );
				html += '<p class="description">Videz les caches (extension de cache, CSS générés par votre constructeur de pages) pour voir le résultat sur le site.</p>';
				html += '<p><button type="button" class="button" data-search="dismiss">Terminer</button> <button type="button" class="button-link wpmig-danger" data-search="undo">Annuler ce remplacement</button></p>';
			} else if ( state.status === 'undone' ) {
				html += '<div class="notice notice-info inline"><p><strong>Remplacement annulé.</strong> ' + esc( state.undo.restored ) + ' valeur(s) restaurée(s)' + ( state.undo.kept ? ', ' + esc( state.undo.kept ) + ' laissée(s) telle(s) quelle(s) car modifiée(s) depuis' : '' ) + '.</p></div>';
				html += '<p><button type="button" class="button" data-search="dismiss">Fermer</button></p>';
			}
			html += '</div>';
			searchPanel.innerHTML = html;
			searchForm.hidden = true;
		};

		var searchLoop = function ( state ) {
			searchRetries = 0;
			renderSearch( state );
			if ( searchRunning.indexOf( state.status ) === -1 ) {
				return;
			}
			setTimeout( function () {
				post( 'wpmig_search_step' ).then( searchLoop ).catch( function ( err ) {
					if ( err.retry && searchRetries++ < 5 ) {
						setTimeout( function () {
							searchLoop( state );
						}, 3000 * searchRetries );
						return;
					}
					searchPanel.insertAdjacentHTML( 'beforeend', '<div class="notice notice-error inline"><p>' + esc( err.message ) + '</p><p><button type="button" class="button" data-search="resume">Reprendre</button></p></div>' );
				} );
			}, 200 );
		};

		var searchModeChanged = function () {
			var mode = searchMode();
			searchForm.querySelectorAll( 'label[data-mode]' ).forEach( function ( l ) {
				l.hidden = l.getAttribute( 'data-mode' ) !== mode;
			} );
		};
		searchForm.querySelectorAll( 'input[name="wpmig-search-mode"]' ).forEach( function ( r ) {
			r.addEventListener( 'change', searchModeChanged );
		} );
		searchModeChanged();

		searchForm.addEventListener( 'click', function ( e ) {
			var which = e.target.getAttribute( 'data-search-tables' );
			if ( which ) {
				searchForm.querySelectorAll( 'input[name="wpmig-search-table"]' ).forEach( function ( c ) {
					c.checked = which === 'all';
				} );
			}
		} );

		searchForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var all = searchForm.querySelectorAll( 'input[name="wpmig-search-table"]' );
			var picked = [];
			all.forEach( function ( c ) {
				if ( c.checked ) {
					picked.push( c.value );
				}
			} );
			if ( ! picked.length ) {
				window.alert( 'Cochez au moins une table.' );
				return;
			}
			var button = $( 'button[type="submit"]', searchForm );
			button.disabled = true;
			post( 'wpmig_search_start', {
				search: $( '#wpmig-search-text' ).value,
				replace: $( '#wpmig-search-replace' ).value,
				mode: searchMode(),
				ignore_case: $( '#wpmig-search-case' ).checked ? 1 : '',
				www: $( '#wpmig-search-www' ).checked ? 1 : '',
				variants: $( '#wpmig-search-variants' ).checked ? 1 : '',
				guid: $( '#wpmig-search-guid' ).checked ? 1 : '',
				tables: picked.length === all.length ? '' : picked.join( ',' )
			} ).then( function ( state ) {
				button.disabled = false;
				searchLoop( state );
			} ).catch( function ( err ) {
				button.disabled = false;
				searchPanel.innerHTML = '<div class="notice notice-error inline"><p>' + esc( err.message ) + '</p></div>';
			} );
		} );

		search.addEventListener( 'click', function ( e ) {
			var target = e.target;
			var suggest = target.getAttribute( 'data-search-suggest' );
			if ( suggest ) {
				var pair = suggest.split( '|' );
				$( '#wpmig-search-text' ).value = pair[ 0 ];
				$( '#wpmig-search-replace' ).value = pair[ 1 ] || '';
				$( '#wpmig-search-replace' ).focus();
				return;
			}
			var undoId = target.getAttribute( 'data-search-undo' );
			var action = undoId ? 'undo' : target.getAttribute( 'data-search' );
			if ( ! action ) {
				return;
			}
			if ( action === 'undo' && ! window.confirm( 'Annuler ce remplacement ? Les valeurs d\'origine sont remises, sauf celles modifiées depuis.' ) ) {
				return;
			}
			if ( action === 'confirm' && ! window.confirm( 'Remplacer maintenant dans la base de données de ce site ?' ) ) {
				return;
			}
			if ( action === 'backup' ) {
				target.disabled = true;
				backupDb( $( '#wpmig-search-backup', search ), 'avant-remplacement' ).catch( function () {} ).then( function () {
					target.disabled = false;
				} );
				return;
			}
			target.disabled = true;
			if ( action === 'dismiss' ) {
				post( 'wpmig_search_dismiss' ).then( function () {
					window.location.reload();
				} ).catch( function ( err ) {
					target.disabled = false;
					window.alert( err.message );
				} );
				return;
			}
			post( action === 'resume' ? 'wpmig_search_step' : 'wpmig_search_' + action, undoId ? { id: undoId } : {} ).then( searchLoop ).catch( function ( err ) {
				target.disabled = false;
				window.alert( err.message );
			} );
		} );

		var searchInitial = search.getAttribute( 'data-state' );
		if ( searchInitial ) {
			searchLoop( JSON.parse( searchInitial ) );
		}
	}

	/* Settings taken from another site. */
	var settings = document.getElementById( 'wpmig-settings' );
	if ( settings ) {
		var settingsForm = $( '#wpmig-settings-form' );
		var settingsPanel = $( '#wpmig-settings-panel' );
		var settingsStatus = { 'new': 'Nouveau ici', different: 'Différent', same: 'Identique', missing: 'Absent de l\'origine', protected: 'Jamais copié', too_big: 'Trop volumineux' };
		var settingsError = function ( err ) {
			settingsPanel.innerHTML = '<div class="notice notice-error inline"><p>' + esc( err.message ) + '</p></div>';
		};
		var settingsChosen = { names: [], adapt: 1 };
		var settingsPicked = function () {
			var names = [];
			settingsPanel.querySelectorAll( 'input[name="wpmig-settings-name"]:checked' ).forEach( function ( c ) {
				names.push( c.value );
			} );
			return names;
		};
		var settingsRequest = function () {
			return {
				link: $( '#wpmig-settings-link' ).value,
				names: settingsChosen.names.join( '\n' ),
				adapt: settingsChosen.adapt
			};
		};
		var settingsSearch = function () {
			var q = $( '#wpmig-settings-q' ).value.replace( /^\*$/, '' );
			var button = $( 'button[type="submit"]', settingsForm );
			button.disabled = true;
			settingsPanel.innerHTML = '<p class="wpmig-msg">Lecture des réglages du site d\'origine…</p>';
			post( 'wpmig_settings_list', { link: $( '#wpmig-settings-link' ).value, q: q } ).then( function ( data ) {
				button.disabled = false;
				var html = '';
				if ( ! data.options.length ) {
					settingsPanel.innerHTML = '<p>Aucun réglage trouvé' + ( q ? ' pour « ' + esc( q ) + ' »' : '' ) + '.</p>';
					return;
				}
				html += '<p>' + esc( data.total ) + ' réglage(s) trouvé(s)' + ( data.total > data.options.length ? ' (les ' + esc( data.options.length ) + ' premiers sont affichés : affinez la recherche)' : '' ) + '. Cochez ceux à reprendre.</p>';
				html += '<table class="widefat striped wpmig-settings-list"><thead><tr><th></th><th>Réglage</th><th class="num">Taille</th><th>Sur ce site</th></tr></thead><tbody>';
				data.options.forEach( function ( o ) {
					html += '<tr><td><input type="checkbox" name="wpmig-settings-name" value="' + esc( o.name ) + '"></td><td><code>' + esc( o.name ) + '</code></td><td class="num">' + esc( o.size ) + ' o</td><td>' + ( o.local ? 'Existe' : '<em>Absent</em>' ) + '</td></tr>';
				} );
				html += '</tbody></table>';
				html += '<p><label><input type="checkbox" id="wpmig-settings-adapt" checked> Remplacer les adresses du site d\'origine par celles de ce site</label></p>';
				html += '<p><button type="button" class="button button-primary" data-settings="preview">Comparer avec ce site</button></p>';
				settingsPanel.innerHTML = html;
			} ).catch( function ( err ) {
				button.disabled = false;
				settingsError( err );
			} );
		};
		var settingsPreview = function ( plan ) {
			var html = '<h3>Comparaison avec ce site</h3><p>Origine : <code>' + esc( plan.source ) + '</code>. Rien n\'est encore modifié.</p>';
			var copy = 0;
			html += '<table class="widefat striped wpmig-settings-plan"><thead><tr><th>Réglage</th><th>État</th><th>Détail</th></tr></thead><tbody>';
			plan.items.forEach( function ( item ) {
				var detail = '';
				if ( item.status === 'new' || item.status === 'different' ) {
					copy++;
				}
				if ( item.changes && item.changes.length ) {
					detail += '<details><summary>' + esc( item.changes.length ) + ' différence(s)</summary><ul class="wpmig-changes">';
					item.changes.forEach( function ( line ) {
						detail += '<li><code>' + esc( line ) + '</code></li>';
					} );
					detail += '</ul></details>';
				} else if ( item.status === 'new' ) {
					detail += esc( item.new_size ) + ' o seront créés' + ( item.secret ? ' (contient probablement des identifiants)' : '' ) + '.';
				}
				if ( item.rewritten ) {
					detail += ' <em>Adresses adaptées à ce site.</em>';
				}
				if ( item.note ) {
					detail += ( detail ? '<br>' : '' ) + '<span class="description">' + esc( item.note ) + '</span>';
				}
				html += '<tr><td><code>' + esc( item.name ) + '</code></td><td><span class="wpmig-badge wpmig-badge-' + esc( item.status ) + '">' + esc( settingsStatus[ item.status ] || item.status ) + '</span></td><td>' + detail + '</td></tr>';
			} );
			html += '</tbody></table>';
			if ( copy ) {
				html += '<p><button type="button" class="button button-primary" data-settings="apply">Copier ' + esc( copy ) + ' réglage(s)</button> <button type="button" class="button" data-settings="backup">Sauvegarder la base de données</button> <button type="button" class="button" data-settings="cancel">Annuler</button></p><div id="wpmig-settings-backup"></div><p class="description">Conseil : sauvegardez d\'abord la base de données. La copie peut aussi être annulée juste après.</p>';
			} else {
				html += '<p>Rien à copier. <button type="button" class="button" data-settings="cancel">Fermer</button></p>';
			}
			settingsPanel.innerHTML = html;
		};

		settingsForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			settingsSearch();
		} );

		settings.addEventListener( 'click', function ( e ) {
			var target = e.target;
			var q = target.getAttribute( 'data-settings-q' );
			if ( q ) {
				$( '#wpmig-settings-q' ).value = q === '*' ? '' : q;
				if ( $( '#wpmig-settings-link' ).value ) {
					settingsSearch();
				} else {
					$( '#wpmig-settings-link' ).focus();
				}
				return;
			}
			var undoId = target.getAttribute( 'data-settings-undo' );
			var action = undoId ? 'undo' : target.getAttribute( 'data-settings' );
			if ( ! action ) {
				return;
			}
			if ( action === 'cancel' ) {
				settingsPanel.innerHTML = '';
				return;
			}
			if ( action === 'backup' ) {
				target.disabled = true;
				backupDb( $( '#wpmig-settings-backup', settings ), 'avant-reglages' ).catch( function () {} ).then( function () {
					target.disabled = false;
				} );
				return;
			}
			if ( action === 'preview' ) {
				settingsChosen = { names: settingsPicked(), adapt: $( '#wpmig-settings-adapt' ).checked ? 1 : '' };
				if ( ! settingsChosen.names.length ) {
					window.alert( 'Cochez au moins un réglage.' );
					return;
				}
				target.disabled = true;
				post( 'wpmig_settings_preview', settingsRequest() ).then( settingsPreview ).catch( function ( err ) {
					target.disabled = false;
					window.alert( err.message );
				} );
				return;
			}
			if ( action === 'undo' && ! window.confirm( 'Annuler cette copie ? Les réglages d\'origine sont remis, sauf ceux modifiés depuis.' ) ) {
				return;
			}
			if ( action === 'apply' && ! window.confirm( 'Copier ces réglages sur ce site ?' ) ) {
				return;
			}
			target.disabled = true;
			if ( action === 'apply' ) {
				post( 'wpmig_settings_apply', settingsRequest() ).then( function ( res ) {
					var html = '<div class="notice notice-success inline"><p><strong>Copie terminée.</strong> ' + esc( res.changed ) + ' réglage(s) copié(s) dont ' + esc( res.new ) + ' nouveau(x).</p></div>';
					html += '<p><button type="button" class="button" data-settings="reload">Terminer</button> ' + ( res.id ? '<button type="button" class="button-link wpmig-danger" data-settings-undo="' + esc( res.id ) + '">Annuler cette copie</button>' : '' ) + '</p>';
					settingsPanel.innerHTML = html;
				} ).catch( function ( err ) {
					target.disabled = false;
					window.alert( err.message );
				} );
				return;
			}
			if ( action === 'reload' ) {
				window.location.reload();
				return;
			}
			post( 'wpmig_settings_undo', { id: undoId } ).then( function ( res ) {
				var html = '<div class="notice notice-success inline"><p><strong>Copie annulée.</strong> ' + esc( res.restored ) + ' réglage(s) remis en état' + ( res.kept.length ? ' ; ' + esc( res.kept.length ) + ' conservé(s) car modifié(s) depuis (' + esc( res.kept.join( ', ' ) ) + ')' : '' ) + '.</p></div><p><button type="button" class="button" data-settings="reload">Terminer</button></p>';
				settingsPanel.innerHTML = html;
			} ).catch( function ( err ) {
				target.disabled = false;
				window.alert( err.message );
			} );
		} );
	}

	/* Comparison with another site. */
	var compare = document.getElementById( 'wpmig-compare' );
	if ( compare ) {
		var compareForm = $( '#wpmig-compare-form' );
		var comparePanel = $( '#wpmig-compare-panel' );
		var compareStatus = { same: 'Identique', diff: 'Différent', only_here: 'Seulement ici', only_there: 'Seulement là-bas', info: 'À titre indicatif' };
		var compareRender = function ( res ) {
			var s = res.summary;
			var html = '<h3>Résultat</h3><p>Autre site : <code>' + esc( res.source ) + '</code>. <strong>' + esc( s.diff + s.only_here + s.only_there ) + '</strong> différence(s) à examiner, ' + esc( s.info ) + ' à titre indicatif, ' + esc( s.same ) + ' identique(s).</p>';
			html += '<p><label><input type="checkbox" id="wpmig-compare-all"> Afficher aussi les lignes identiques</label></p>';
			res.sections.forEach( function ( section ) {
				var differing = section.rows.filter( function ( row ) {
					return row.status !== 'same';
				} ).length;
				html += '<h4>' + esc( section.title ) + '</h4>' + ( differing ? '' : '<p class="wpmig-compare-allsame">Identique (' + esc( section.rows.length ) + ' élément(s)).</p>' ) + '<table class="widefat striped wpmig-compare-table' + ( differing ? '' : ' wpmig-compare-same-only' ) + '"><thead><tr><th>Élément</th><th>Ce site</th><th>Autre site</th><th>État</th></tr></thead><tbody>';
				section.rows.forEach( function ( row ) {
					var pick = row.option && ( row.status === 'diff' || row.status === 'only_there' ) ? ' <button type="button" class="button-link" data-compare-pick="' + esc( row.option ) + '">Reprendre</button>' : '';
					html += '<tr class="wpmig-compare-' + esc( row.status ) + '"><td>' + esc( row.label ) + '</td><td>' + ( row.here === '' ? '<em>absent</em>' : esc( row.here ) ) + '</td><td>' + ( row.there === '' ? '<em>absent</em>' : esc( row.there ) ) + '</td><td><span class="wpmig-badge wpmig-badge-cmp-' + esc( row.status ) + '">' + esc( compareStatus[ row.status ] ) + '</span>' + pick + '</td></tr>';
				} );
				html += '</tbody></table>';
			} );
			comparePanel.innerHTML = html;
			comparePanel.classList.remove( 'wpmig-compare-showall' );
		};
		compareForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var button = $( 'button[type="submit"]', compareForm );
			button.disabled = true;
			comparePanel.innerHTML = '<p class="wpmig-msg">Lecture des deux sites…</p>';
			post( 'wpmig_compare_run', { link: $( '#wpmig-compare-link' ).value } ).then( function ( res ) {
				button.disabled = false;
				compareRender( res );
			} ).catch( function ( err ) {
				button.disabled = false;
				comparePanel.innerHTML = '<div class="notice notice-error inline"><p>' + esc( err.message ) + '</p></div>';
			} );
		} );
		compare.addEventListener( 'change', function ( e ) {
			if ( e.target.id === 'wpmig-compare-all' ) {
				comparePanel.classList.toggle( 'wpmig-compare-showall', e.target.checked );
			}
		} );
		compare.addEventListener( 'click', function ( e ) {
			var option = e.target.getAttribute( 'data-compare-pick' );
			var settingsLink = $( '#wpmig-settings-link' );
			if ( ! option || ! settingsLink ) {
				return;
			}
			settingsLink.value = $( '#wpmig-compare-link' ).value;
			$( '#wpmig-settings-q' ).value = option;
			$( '#wpmig-settings' ).scrollIntoView();
			$( '#wpmig-settings-form button[type="submit"]' ).click();
		} );
	}
}() );
