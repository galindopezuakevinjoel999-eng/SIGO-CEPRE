'use client';

import { useEffect, useRef, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';

// Definida en frontend/.env.local (ver .env.local.example).
const API_URL = process.env.NEXT_PUBLIC_API_URL ?? 'http://127.0.0.1:8000';

// Mensajes para los códigos de error que envía el backend en ?error=
const ERROR_MESSAGES: Record<string, string> = {
  google_failed: 'No se pudo completar el inicio de sesión con Google. Inténtalo de nuevo.',
  email_not_verified: 'Tu correo de Google no está verificado.',
  account_inactive: 'Tu cuenta no está activa. Comunícate con la administración.',
  account_disabled: 'Tu cuenta está inhabilitada y no puedes iniciar sesión.',
};

/**
 * Canjea el código de un solo uso que dejó el backend por el token de Sanctum.
 * Devuelve el token, o lanza un Error con el mensaje para el usuario.
 */
async function exchangeCode(params: URLSearchParams): Promise<string> {
  const code = params.get('code');
  const errorCode = params.get('error');

  if (errorCode || !code) {
    throw new Error(ERROR_MESSAGES[errorCode ?? ''] ?? ERROR_MESSAGES.google_failed);
  }

  const res = await fetch(`${API_URL}/api/auth/google/exchange`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ code }),
  });
  const data = await res.json().catch(() => ({}));

  if (!res.ok || !data.token) {
    throw new Error(data.message ?? ERROR_MESSAGES.google_failed);
  }

  return data.token;
}

export default function Callback() {
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);
  // Evita canjear el código dos veces (en desarrollo, React ejecuta los efectos dos veces).
  const started = useRef(false);

  useEffect(() => {
    if (started.current) return;
    started.current = true;

    const params = new URLSearchParams(window.location.search);
    // Quita el código de la barra de direcciones y del historial.
    window.history.replaceState(null, '', window.location.pathname);

    exchangeCode(params)
      .then((token) => {
        // TODO(fase frontend): mover el token a una cookie httpOnly. En localStorage
        // cualquier script de la página (p. ej. un XSS) puede leerlo.
        localStorage.setItem('token', token);
        router.push('/student/dashboard');
      })
      .catch((e: Error) => setError(e.message || ERROR_MESSAGES.google_failed));
  }, [router]);

  if (error) {
    return (
      <div>
        <p>{error}</p>
        <Link href="/">Volver al inicio</Link>
      </div>
    );
  }

  return <p>Iniciando sesión...</p>;
}
