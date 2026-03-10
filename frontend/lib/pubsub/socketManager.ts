/**
 * Socket Manager - Singleton pattern for Socket.IO connection management.
 *
 * This module provides a centralized socket instance that:
 * - Automatically negotiates credentials with the backend
 * - Handles reconnection with token refresh
 * - Prevents duplicate connections
 */

import { io, Socket } from 'socket.io-client';
import { negotiate, NegotiateResponse } from './negotiate';

class SocketManager {
  private socket: Socket | null = null;
  private credentials: NegotiateResponse | null = null;
  private connecting = false;
  private reconnectAttempts = 0;
  private maxReconnectAttempts = 5;

  /**
   * Get the current socket instance, creating one if necessary.
   */
  async getSocket(): Promise<Socket> {
    if (this.socket?.connected) {
      return this.socket;
    }

    if (this.connecting) {
      // Wait for existing connection attempt with timeout
      return new Promise((resolve, reject) => {
        const timeout = setTimeout(() => {
          reject(new Error('Connection timeout'));
        }, 10000); // 10 second timeout

        const checkInterval = setInterval(() => {
          if (this.socket?.connected) {
            clearTimeout(timeout);
            clearInterval(checkInterval);
            resolve(this.socket);
          } else if (!this.connecting) {
            clearTimeout(timeout);
            clearInterval(checkInterval);
            reject(new Error('Connection failed'));
          }
        }, 100);
      });
    }

    return this.connect();
  }

  /**
   * Establish a new socket connection.
   */
  private async connect(): Promise<Socket> {
    this.connecting = true;

    try {
      // Get credentials from backend
      this.credentials = await negotiate();

      // Create socket connection with Socket.IO options
      this.socket = io(this.credentials.endpoint, {
        path: `/clients/socketio/hubs/${this.credentials.hub}`,
        query: {
          access_token: this.credentials.token,
        },
        transports: ['websocket'], // MUST use websocket only for Azure Web PubSub
        reconnection: true,
        reconnectionAttempts: this.maxReconnectAttempts,
        reconnectionDelay: 2000,
        reconnectionDelayMax: 10000,
      });

      // Handle reconnection with token refresh
      this.socket.on('reconnect_attempt', async () => {
        // Check if token is expired and refresh if needed
        if (this.credentials && Date.now() >= this.credentials.expires * 1000) {
          try {
            this.credentials = await negotiate();
            if (this.socket) {
              this.socket.io.opts.query = {
                access_token: this.credentials.token,
              };
            }
          } catch (error) {
            console.error('Failed to refresh token during reconnection:', error);
          }
        }
      });

      this.socket.on('connect', () => {
        console.log('Socket connected');
        this.reconnectAttempts = 0;
        this.connecting = false; // Reset connecting flag on successful connection
        // Group membership is handled automatically by the backend event handler
      });

      this.socket.on('disconnect', (reason) => {
        console.log('Socket disconnected:', reason);
      });

      this.socket.on('connect_error', (error) => {
        console.error('Socket connection error:', error);
        this.reconnectAttempts++;
        this.connecting = false; // Reset connecting flag on connection error
      });

      return new Promise((resolve, reject) => {
        this.socket!.on('connect', () => resolve(this.socket!));
        this.socket!.on('connect_error', (error) => {
          this.connecting = false;
          reject(error);
        });
      });
    } catch (error) {
      this.connecting = false;
      throw error;
    }
    // Note: No finally block - connecting flag is reset in event handlers
  }

  /**
   * Disconnect the socket.
   * Use with caution - typically you want to keep the connection alive.
   */
  disconnect(): void {
    if (this.socket) {
      this.socket.disconnect();
      this.socket = null;
      this.credentials = null;
    }
  }

  /**
   * Check if the socket is currently connected.
   */
  isConnected(): boolean {
    return this.socket?.connected ?? false;
  }

  /**
   * Get the current credentials (useful for debugging).
   */
  getCredentials(): NegotiateResponse | null {
    return this.credentials;
  }
}

// Export singleton instance
export const socketManager = new SocketManager();
