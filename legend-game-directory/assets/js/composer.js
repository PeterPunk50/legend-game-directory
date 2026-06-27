/* Legend Game Directory — AI Layout Composer (guide editor) */
( function ( $ ) {
	$( function () {
		var $box = $( '#lgd_guide_composer' );
		if ( ! $box.length || typeof LGDComposer === 'undefined' ) { return; }

		var $text   = $box.find( '#lgd-comp-text' );
		var $imgs   = $box.find( '#lgd-comp-images' );
		var $out    = $box.find( '#lgd-comp-output' );
		var $prev   = $box.find( '#lgd-comp-preview' );
		var $result = $box.find( '.lgd-comp-result' );
		var frame;

		// Media Library picker -> append URLs.
		$box.on( 'click', '#lgd-comp-addimg', function ( e ) {
			e.preventDefault();
			if ( ! window.wp || ! wp.media ) { return; }
			if ( frame ) { frame.open(); return; }
			frame = wp.media( { title: 'Select images', multiple: true, library: { type: 'image' } } );
			frame.on( 'select', function () {
				var urls = [];
				frame.state().get( 'selection' ).each( function ( att ) {
					var u = att.toJSON().url;
					if ( u ) { urls.push( u ); }
				} );
				var cur = $.trim( $imgs.val() );
				$imgs.val( ( cur ? cur + '\n' : '' ) + urls.join( '\n' ) );
			} );
			frame.open();
		} );

		// Arrange with AI.
		$box.on( 'click', '#lgd-comp-run', function ( e ) {
			e.preventDefault();
			var btn    = this;
			var images = $imgs.val().split( /\n+/ ).map( function ( s ) { return $.trim( s ); } ).filter( Boolean );
			if ( ! $.trim( $text.val() ) && ! images.length ) { window.alert( LGDComposer.i18n.empty ); return; }

			$( btn ).prop( 'disabled', true ).text( LGDComposer.i18n.running );
			$.post( LGDComposer.ajax, {
				action: 'lgd_compose_guide',
				nonce:  LGDComposer.nonce,
				text:   $text.val(),
				images: images
			} ).done( function ( res ) {
				if ( res && res.success ) {
					$out.val( res.data.html );
					$prev.html( res.data.html );
					$result.show();
				} else {
					window.alert( ( res && res.data ) ? res.data : LGDComposer.i18n.failed );
				}
			} ).fail( function () {
				window.alert( LGDComposer.i18n.failed );
			} ).always( function () {
				$( btn ).prop( 'disabled', false ).text( LGDComposer.i18n.run );
			} );
		} );

		// Insert into the editor content.
		$box.on( 'click', '#lgd-comp-insert', function ( e ) {
			e.preventDefault();
			var html = $out.val();
			if ( ! html ) { return; }

			if ( window.wp && wp.data && wp.blocks && wp.data.select( 'core/block-editor' ) ) {
				var blocks = wp.blocks.rawHandler( { HTML: html } );
				wp.data.dispatch( 'core/block-editor' ).insertBlocks( blocks );
			} else if ( window.tinymce && tinymce.activeEditor && ! tinymce.activeEditor.isHidden() ) {
				tinymce.activeEditor.execCommand( 'mceInsertContent', false, html );
			} else {
				var $content = $( '#content' );
				if ( $content.length ) { $content.val( $content.val() + '\n' + html ); }
			}
			window.alert( LGDComposer.i18n.inserted );
		} );
	} );
} )( jQuery );
