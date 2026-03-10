#!/usr/bin/env node

/**
 * Azure Web PubSub Socket.IO Connection Test
 *
 * This script tests the actual Socket.IO connection to Azure Web PubSub
 */

const io = require('socket.io-client');

// Configuration from our test
const AZURE_ENDPOINT = 'https://qaautoallies.webpubsub.azure.com';
const AZURE_HUB = 'chat';
const AZURE_TOKEN = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpYXQiOjE3NzMxMDMwNDcuNjUwMDA0LCJleHAiOjE3NzMxMDY2NDcuNjUwMDA0LCJhdWQiOiJodHRwczovL3FhYXV0b2FsbGllcy53ZWJwdWJzdWIuYXp1cmUuY29tL2NsaWVudHMvc29ja2V0aW8vaHVicy9jaGF0Iiwic3ViIjoiMSJ9.qUJpPvvyUiWRT5bhdWDLDplrZzFCFCqoSKUwWTso3OA';

console.log('========================================');
console.log('Azure Web PubSub Socket.IO Connection Test');
console.log('========================================\n');

console.log('Connection Details:');
console.log(`  Endpoint: ${AZURE_ENDPOINT}`);
console.log(`  Hub: ${AZURE_HUB}`);
console.log(`  Path: /clients/socketio/hubs/${AZURE_HUB}`);
console.log(`  Token: ${AZURE_TOKEN.substring(0, 30)}...\n`);

// Create socket connection
const socket = io(AZURE_ENDPOINT, {
  path: `/clients/socketio/hubs/${AZURE_HUB}`,
  query: {
    access_token: AZURE_TOKEN
  },
  transports: ['websocket'],
  reconnection: false
});

let testPassed = false;
let connectionTimeout;
let messageTimeout;

// Set up timeout for the entire test
const testTimeout = setTimeout(() => {
  console.error('\n❌ TEST FAILED: Connection timeout (30 seconds)');
  console.log('This could indicate:');
  console.log('  1. Network connectivity issues');
  console.log('  2. Azure Web PubSub service is down');
  console.log('  3. Invalid credentials or hub configuration');
  console.log('  4. Firewall blocking WebSocket connections\n');
  socket.disconnect();
  process.exit(1);
}, 30000);

socket.on('connect', () => {
  console.log('✅ Socket connected successfully!');
  console.log(`   Socket ID: ${socket.id}\n`);

  // Clear connection timeout
  clearTimeout(connectionTimeout);

  // Test message handling
  socket.on('message', (data) => {
    console.log('✅ Received message event:');
    console.log('   ', JSON.stringify(data, null, 2));
    clearTimeout(messageTimeout);
  });

  socket.on('connect_error', (error) => {
    console.error('❌ Connection error:', error.message);
    clearTimeout(testTimeout);
    process.exit(1);
  });

  socket.on('error', (error) => {
    console.error('❌ Socket error:', error);
    clearTimeout(testTimeout);
    process.exit(1);
  });

  // If we get here, connection was successful
  testPassed = true;

  // Wait a bit to ensure stable connection
  setTimeout(() => {
    console.log('✅ Connection stable for 3 seconds\n');

    console.log('========================================');
    console.log('Test Results: PASSED ✅');
    console.log('========================================\n');

    console.log('Verified:');
    console.log('  ✓ Socket.IO client library working');
    console.log('  ✓ WebSocket transport established');
    console.log('  ✓ Azure Web PubSub authentication successful');
    console.log('  ✓ Hub connection established\n');

    clearTimeout(testTimeout);
    socket.disconnect();
    process.exit(0);
  }, 3000);
});

socket.on('disconnect', (reason) => {
  console.log(`\n⚠️  Socket disconnected: ${reason}`);
  if (!testPassed) {
    clearTimeout(testTimeout);
    process.exit(1);
  }
});

// Connection timeout
connectionTimeout = setTimeout(() => {
  console.error('\n❌ Connection timeout (10 seconds)');
  console.log('Failed to establish WebSocket connection.\n');
  clearTimeout(testTimeout);
  socket.disconnect();
  process.exit(1);
}, 10000);

console.log('Attempting to connect...\n');
