'use client';

/**
 * ChatWindow Component
 *
 * Real-time messaging component using Azure Web PubSub for Socket.IO.
 */

import { useEffect, useState, useCallback, useRef } from 'react';
import { useSocket } from '@/hooks/useSocket';

interface Message {
  id: string;
  userId: string;
  userName?: string;
  content: string;
  timestamp: Date;
}

interface ChatWindowProps {
  userId: string;
}

export function ChatWindow({ userId }: ChatWindowProps) {
  const { socket, isConnected, isLoading, error, connect } = useSocket(false);
  const [messages, setMessages] = useState<Message[]>([]);
  const [inputValue, setInputValue] = useState('');
  const [lastMessageTime, setLastMessageTime] = useState<string>('');
  const messagesEndRef = useRef<HTMLDivElement>(null);

  // Auto-scroll to bottom when new messages are added
  const scrollToBottom = () => {
    messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
  };

  useEffect(() => {
    scrollToBottom();
  }, [messages]);

  // Connect to socket on mount
  useEffect(() => {
    connect();
  }, [connect]);

  // Polling fallback for new messages (in case WebSocket doesn't work)
  // This ensures messages appear for all users even without Azure event handler configured
  const lastMessageRef = useRef<Message | null>(null);

  // Update ref when messages change
  useEffect(() => {
    if (messages.length > 0) {
      lastMessageRef.current = messages[messages.length - 1];
    }
  }, [messages]);

  useEffect(() => {
    const pollInterval = 2000; // Poll every 2 seconds

    const pollForNewMessages = async () => {
      const lastMsg = lastMessageRef.current;
      if (!lastMsg) return;

      const afterTimestamp = new Date(lastMsg.timestamp).toISOString();

      try {
        console.log('[ChatWindow] Polling for messages after:', afterTimestamp);

        const response = await fetch(
          `http://localhost:8000/api/messages?after=${encodeURIComponent(afterTimestamp)}`,
          {
            credentials: 'include',
            headers: { Accept: 'application/json' },
          }
        );

        if (response.ok) {
          const data = await response.json();
          const newMessages = Array.isArray(data.messages) ? data.messages : Object.values(data.messages || {});

          console.log('[ChatWindow] Polling response:', {
            count: newMessages.length,
            messages: newMessages,
          });

          if (newMessages.length > 0) {
            console.log('[ChatWindow] Polling: received new messages:', newMessages.length);

            // Add new messages (avoiding duplicates)
            setMessages((prev) => {
              const existingIds = new Set(prev.map((msg) => msg.id));
              const uniqueNewMessages = newMessages.filter((msg: Message) => !existingIds.has(msg.id));

              if (uniqueNewMessages.length === 0) {
                console.log('[ChatWindow] All messages were duplicates');
                return prev;
              }

              console.log('[ChatWindow] Adding', uniqueNewMessages.length, 'new messages');

              return [
                ...prev,
                ...uniqueNewMessages.map((msg: Message) => ({
                  ...msg,
                  timestamp: new Date(msg.timestamp),
                })),
              ];
            });
          } else {
            console.log('[ChatWindow] No new messages from polling');
          }
        } else {
          console.error('[ChatWindow] Polling failed:', response.status, response.statusText);
        }
      } catch (error) {
        console.error('[ChatWindow] Polling error:', error);
      }
    };

    // Start polling
    const intervalId = setInterval(pollForNewMessages, pollInterval);

    return () => clearInterval(intervalId);
  }, []); // Empty deps - polling runs continuously

  // Load initial messages from database
  useEffect(() => {
    const fetchInitialMessages = async () => {
      try {
        const response = await fetch('http://localhost:8000/api/messages', {
          credentials: 'include',
          headers: { Accept: 'application/json' },
        });

        if (response.ok) {
          const data = await response.json();
          const fetchedMessages = Array.isArray(data.messages) ? data.messages : Object.values(data.messages || {});

          // Convert timestamps to Date objects and sort by timestamp
          const sortedMessages = fetchedMessages
            .map((msg: Message) => ({
              ...msg,
              timestamp: new Date(msg.timestamp),
            }))
            .sort((a: Message, b: Message) =>
              new Date(a.timestamp).getTime() - new Date(b.timestamp).getTime()
            );

          setMessages(sortedMessages);
        }
      } catch (error) {
        console.error('[ChatWindow] Error fetching initial messages:', error);
      }
    };

    fetchInitialMessages();
  }, []);

  // Handle incoming messages
  useEffect(() => {
    if (!socket) return;

    const handleMessage = (data: Message) => {
      console.log('[ChatWindow] Received message:', data);

      setMessages((prev) => {
        // Check if we already have this message (avoid duplicates)
        if (prev.some((msg) => msg.id === data.id)) {
          return prev;
        }

        // Handle optimistic update replacement
        // Temporary IDs start with 'temp-' prefix (from crypto.randomUUID())
        const optimisticIndex = prev.findIndex((msg) =>
          msg.id.startsWith('temp-') &&
          msg.userId === data.userId &&
          msg.content === data.content &&
          Math.abs(msg.timestamp.getTime() - new Date(data.timestamp).getTime()) < 5000 // Within 5 seconds
        );

        if (optimisticIndex !== -1) {
          // Replace optimistic message with confirmed message
          const updatedMessages = [...prev];
          updatedMessages[optimisticIndex] = {
            ...data,
            timestamp: new Date(data.timestamp),
          };
          return updatedMessages;
        }

        // Add new message
        return [
          ...prev,
          {
            ...data,
            timestamp: new Date(data.timestamp),
          },
        ];
      });
    };

    socket.on('message', handleMessage);

    // Socket event logging
    socket.on('connect', () => {
      console.log('[ChatWindow] Socket connected:', socket.id);
    });

    socket.on('disconnect', (reason) => {
      console.log('[ChatWindow] Socket disconnected:', reason);
    });

    socket.on('error', (error) => {
      console.error('[ChatWindow] Socket error:', error);
    });

    return () => {
      socket.off('message', handleMessage);
      socket.off('connect');
      socket.off('disconnect');
      socket.off('error');
    };
  }, [socket]);

  // Send a message via HTTP POST
  const sendMessage = useCallback(async () => {
    if (!inputValue.trim()) return;

    const tempId = `temp-${crypto.randomUUID()}`;
    const content = inputValue.trim();

    // Optimistic UI update - show message immediately
    const tempMessage: Message = {
      id: tempId,
      userId: userId,
      userName: 'You',
      content: content,
      timestamp: new Date(),
    };

    setMessages((prev) => [...prev, tempMessage]);
    setInputValue('');

    try {
      console.log('[ChatWindow] Sending message via HTTP:', content);
      const response = await fetch('http://localhost:8000/api/messages/send', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ content }),
      });

      if (response.ok) {
        const result = await response.json();
        // Optimistic update will be replaced by WebSocket message or polling
        console.log('[ChatWindow] Message sent successfully:', result);
      } else {
        // Remove optimistic message on error
        setMessages((prev) => prev.filter((msg) => msg.id !== tempId));
        console.error('[ChatWindow] Failed to send message:', response.statusText);
      }
    } catch (error) {
      console.error('[ChatWindow] Error sending message:', error);
      // Remove optimistic message on error
      setMessages((prev) => prev.filter((msg) => msg.id !== tempId));
    }
  }, [inputValue, userId]);

  // Handle key press for sending
  const handleKeyPress = (e: React.KeyboardEvent) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendMessage();
    }
  };

  if (error) {
    return (
      <div className="p-4 bg-red-100 text-red-700 rounded-lg">
        <p>Connection Error: {error.message}</p>
        <button
          onClick={connect}
          className="mt-2 px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700"
        >
          Retry Connection
        </button>
      </div>
    );
  }

  return (
    <div className="flex flex-col h-[600px] max-w-2xl mx-auto border rounded-lg overflow-hidden bg-white shadow-lg">
      {/* Header */}
      <div className="flex items-center justify-between p-4 bg-gray-100 border-b">
        <h2 className="text-lg font-semibold">Chat Room</h2>
        <div className="flex items-center gap-2">
          <span
            className={`w-3 h-3 rounded-full ${isConnected ? 'bg-green-500' : isLoading ? 'bg-yellow-500' : 'bg-gray-400'
              }`}
          />
          <span className="text-sm text-gray-600">
            {isLoading ? 'Connecting...' : isConnected ? 'Connected' : 'Disconnected'}
          </span>
        </div>
      </div>

      {/* Messages */}
      <div className="flex-1 overflow-y-auto p-4 space-y-4">
        {messages.length === 0 ? (
          <div className="text-center text-gray-500 py-8">
            <p>No messages yet</p>
            <p className="text-sm mt-2">Send a message to start the conversation!</p>
          </div>
        ) : (
          messages.map((msg) => (
            <div
              key={msg.id}
              className={`flex flex-col ${msg.userId === userId ? 'items-end' : 'items-start'
                }`}
            >
              <span className="text-xs text-gray-500 mb-1">
                {msg.userId === userId ? 'You' : msg.userName || `User ${msg.userId.slice(0, 6)}`}
              </span>
              <div
                className={`max-w-[70%] px-4 py-2 rounded-lg ${msg.userId === userId
                  ? 'bg-blue-500 text-white'
                  : 'bg-gray-200 text-gray-900'
                  }`}
              >
                <p>{msg.content}</p>
              </div>
              <span className="text-xs text-gray-400 mt-1">
                {new Date(msg.timestamp).toLocaleTimeString()}
              </span>
            </div>
          ))
        )}
        <div ref={messagesEndRef} />
      </div>

      {/* Input */}
      <div className="p-4 border-t bg-gray-50">
        <div className="flex gap-2">
          <input
            type="text"
            value={inputValue}
            onChange={(e) => setInputValue(e.target.value)}
            onKeyPress={handleKeyPress}
            placeholder={isConnected ? 'Type a message...' : 'Waiting for connection...'}
            disabled={!isConnected}
            className="flex-1 px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50"
          />
          <button
            onClick={sendMessage}
            disabled={!isConnected || !inputValue.trim()}
            className="px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            Send
          </button>
        </div>
      </div>
    </div>
  );
}
