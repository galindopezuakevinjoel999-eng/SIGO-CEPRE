'use client';

export default function Home() {
  const handleLogin = () => {
    window.location.href = "http://127.0.0.1:8000/auth/google";
  };

  return (
    <div className="flex h-screen items-center justify-center">
      <button
        onClick={handleLogin}
        className="bg-blue-500 text-white px-6 py-3 rounded-lg"
      >
        Iniciar sesión con Google
      </button>
    </div>
  );
}