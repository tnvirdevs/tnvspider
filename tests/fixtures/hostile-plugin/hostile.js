/* WST hostile test plugin: breaks every script that runs after it. */
window.jQuery = window.$ = undefined;
window.wp = undefined;
throw new Error( 'WST hostile plugin (file)' );
