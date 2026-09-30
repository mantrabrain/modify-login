/**
 * Authlify: WebAuthn helpers shared by the login page and the profile screen.
 *
 * Options arrive from PHP (lbuchs/WebAuthn) with binary fields as base64url
 * strings. These helpers turn them into ArrayBuffers for the browser API and
 * turn the browser's response back into base64url strings for PHP.
 *
 * @since 3.0.0
 */
(function (window) {
	'use strict';

	function fromB64url(value) {
		var base64 = String(value).replace(/-/g, '+').replace(/_/g, '/');
		while (base64.length % 4) {
			base64 += '=';
		}
		var binary = window.atob(base64);
		var bytes = new Uint8Array(binary.length);
		for (var i = 0; i < binary.length; i++) {
			bytes[i] = binary.charCodeAt(i);
		}
		return bytes.buffer;
	}

	function toB64url(buffer) {
		if (!buffer) {
			return '';
		}
		var bytes = new Uint8Array(buffer);
		var binary = '';
		for (var i = 0; i < bytes.length; i++) {
			binary += String.fromCharCode(bytes[i]);
		}
		return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}

	function supported() {
		return !!(window.isSecureContext && window.PublicKeyCredential && window.navigator.credentials && window.navigator.credentials.get);
	}

	function publicKey(options) {
		// Accept { publicKey: {...} } or the inner object.
		var pk = options && options.publicKey ? options.publicKey : options;
		return JSON.parse(JSON.stringify(pk));
	}

	function create(options) {
		var pk = publicKey(options);
		pk.challenge = fromB64url(pk.challenge);
		pk.user.id = fromB64url(pk.user.id);
		(pk.excludeCredentials || []).forEach(function (cred) {
			cred.id = fromB64url(cred.id);
		});

		return window.navigator.credentials.create({ publicKey: pk }).then(function (cred) {
			return {
				clientDataJSON: toB64url(cred.response.clientDataJSON),
				attestationObject: toB64url(cred.response.attestationObject),
				transports: typeof cred.response.getTransports === 'function' ? cred.response.getTransports() : []
			};
		});
	}

	function get(options) {
		var pk = publicKey(options);
		pk.challenge = fromB64url(pk.challenge);
		if (pk.allowCredentials && pk.allowCredentials.length) {
			pk.allowCredentials.forEach(function (cred) {
				cred.id = fromB64url(cred.id);
			});
		} else {
			delete pk.allowCredentials;
		}

		return window.navigator.credentials.get({ publicKey: pk }).then(function (cred) {
			return {
				id: toB64url(cred.rawId),
				clientDataJSON: toB64url(cred.response.clientDataJSON),
				authenticatorData: toB64url(cred.response.authenticatorData),
				signature: toB64url(cred.response.signature),
				userHandle: toB64url(cred.response.userHandle)
			};
		});
	}

	/**
	 * Whether an error means the person closed or dismissed the prompt.
	 */
	function cancelled(error) {
		return !!error && (error.name === 'NotAllowedError' || error.name === 'AbortError');
	}

	window.authlifyPasskey = {
		supported: supported,
		create: create,
		get: get,
		cancelled: cancelled,
		fromB64url: fromB64url,
		toB64url: toB64url
	};
})(window);
