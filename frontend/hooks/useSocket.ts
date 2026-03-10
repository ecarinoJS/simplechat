'use client';

/**
 * React hook for accessing the Socket.IO connection.
 *
 * Provides:
 * - socket: The Socket.IO client instance
 * - isConnected: Connection status
 * - error: Any connection error
 * - connect: Function to initiate connection
 * - disconnect: Function to disconnect
 */

import { useEffect, useState, useCallback, useRef } from 'react';
import { Socket } from 'socket.io-client';
import { socketManager } from '@/lib/pubsub/socketManager';

interface UseSocketReturn {
  socket: Socket | null;
  isConnected: boolean;
  isLoading: boolean;
  error: Error | null;
  connect: () => Promise<void>;
  disconnect: () => void;
}

export function useSocket(autoConnect = true): UseSocketReturn {
  const [socket, setSocket] = useState<Socket | null>(null);
  const [isConnected, setIsConnected] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<Error | null>(null);
  const retryCountRef = useRef(0);
  const maxRetries = 3;

  const connect = useCallback(async () => {
    setIsLoading(true);
    setError(null);

    // Reset retry count for manual connection attempts
    if (retryCountRef.current >= maxRetries) {
      retryCountRef.current = 0;
    }

    let lastError: Error | null = null;

    // Retry loop
    while (retryCountRef.current <= maxRetries) {
      try {
        const connectedSocket = await socketManager.getSocket();
        setSocket(connectedSocket);
        setIsConnected(true);
        retryCountRef.current = 0; // Reset on success
        setIsLoading(false);
        return;
      } catch (err) {
        lastError = err instanceof Error ? err : new Error('Connection failed');
        retryCountRef.current++;

        if (retryCountRef.current <= maxRetries) {
          console.log(`[useSocket] Connection attempt ${retryCountRef.current}/${maxRetries} failed, retrying...`);
          // Wait before retrying (session might still be establishing)
          await new Promise(resolve => setTimeout(resolve, 1000));
        }
      }
    }

    // All retries exhausted
    setError(lastError);
    setIsConnected(false);
    setIsLoading(false);
  }, []);

  const disconnect = useCallback(() => {
    socketManager.disconnect();
    setSocket(null);
    setIsConnected(false);
  }, []);

  useEffect(() => {
    if (autoConnect) {
      connect();
    }

    // Note: We do NOT disconnect on unmount to preserve the singleton connection.
    // The socket should persist across component lifecycle changes.
    // Only call disconnect() explicitly when you want to close the connection.

    // Set up connection status listeners
    const handleConnect = () => setIsConnected(true);
    const handleDisconnect = () => setIsConnected(false);

    if (socket) {
      socket.on('connect', handleConnect);
      socket.on('disconnect', handleDisconnect);
    }

    return () => {
      if (socket) {
        socket.off('connect', handleConnect);
        socket.off('disconnect', handleDisconnect);
      }
    };
  }, [autoConnect, connect, socket]);

  return {
    socket,
    isConnected,
    isLoading,
    error,
    connect,
    disconnect,
  };
}
