'use client';

import { useAuth } from '@/hooks/useAuth';
import { ChatWindow } from '@/components/ChatWindow';
import { LoginForm } from '@/components/LoginForm';

export default function Home() {
  const { user, isLoading: authLoading, isAuthenticated, login, logout, error } = useAuth();

  const handleLogout = async () => {
    await logout();
  };

  if (authLoading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-100">
        <div className="text-xl">Loading...</div>
      </div>
    );
  }

  if (!isAuthenticated) {
    return <LoginForm onLogin={login} error={error} isLoading={authLoading} />;
  }

  return (
    <div className="min-h-screen bg-gray-100 py-8 px-4">
      <main className="container mx-auto">
        <div className="flex justify-between items-center mb-8">
          <h1 className="text-3xl font-bold">SimpleChat</h1>
          <div className="flex items-center gap-4">
            <span className="text-gray-600">Welcome, {user?.name}</span>
            <button
              onClick={handleLogout}
              className="px-4 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600"
            >
              Logout
            </button>
          </div>
        </div>
        <ChatWindow userId={user?.id?.toString() || 'unknown'} />
      </main>
    </div>
  );
}
