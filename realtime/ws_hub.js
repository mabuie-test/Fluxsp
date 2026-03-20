const http = require('http');
const crypto = require('crypto');
const { URL } = require('url');

const PORT = Number(process.env.REALTIME_PORT || 8091);
const SECRET = process.env.REALTIME_SHARED_SECRET || '';
const clients = new Set();

function encodeFrame(data) {
  const payload = Buffer.from(data);
  const len = payload.length;
  let header;
  if (len < 126) {
    header = Buffer.from([0x81, len]);
  } else if (len < 65536) {
    header = Buffer.alloc(4);
    header[0] = 0x81;
    header[1] = 126;
    header.writeUInt16BE(len, 2);
  } else {
    header = Buffer.alloc(10);
    header[0] = 0x81;
    header[1] = 127;
    header.writeBigUInt64BE(BigInt(len), 2);
  }
  return Buffer.concat([header, payload]);
}

function send(socket, message) {
  try {
    socket.write(encodeFrame(JSON.stringify(message)));
  } catch (_) {}
}

const server = http.createServer((req, res) => {
  const url = new URL(req.url, `http://${req.headers.host}`);
  if (req.method === 'POST' && url.pathname === '/publish') {
    if (SECRET && req.headers['x-realtime-secret'] !== SECRET) {
      res.writeHead(403).end('forbidden');
      return;
    }
    let raw = '';
    req.on('data', chunk => raw += chunk);
    req.on('end', () => {
      try {
        const body = JSON.parse(raw || '{}');
        for (const client of clients) {
          if (!client.deviceId || client.deviceId !== body.deviceId) continue;
          send(client.socket, {
            event: body.event || 'message',
            deviceId: body.deviceId,
            payload: body.payload || {}
          });
        }
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: true, delivered: true }));
      } catch (err) {
        res.writeHead(400).end('bad_request');
      }
    });
    return;
  }
  res.writeHead(200, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ ok: true, service: 'realtime_ws_hub' }));
});

server.on('upgrade', (req, socket) => {
  const key = req.headers['sec-websocket-key'];
  if (!key) {
    socket.destroy();
    return;
  }
  const accept = crypto.createHash('sha1')
    .update(key + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')
    .digest('base64');
  socket.write([
    'HTTP/1.1 101 Switching Protocols',
    'Upgrade: websocket',
    'Connection: Upgrade',
    `Sec-WebSocket-Accept: ${accept}`,
    '\r\n'
  ].join('\r\n'));

  const client = { socket, deviceId: null };
  clients.add(client);
  send(socket, { event: 'connected' });

  socket.on('data', buffer => {
    try {
      const second = buffer[1];
      let offset = 2;
      let length = second & 0x7f;
      if (length === 126) {
        length = buffer.readUInt16BE(offset);
        offset += 2;
      } else if (length === 127) {
        length = Number(buffer.readBigUInt64BE(offset));
        offset += 8;
      }
      const masked = (second & 0x80) !== 0;
      let payload = buffer.subarray(offset);
      if (masked) {
        const mask = payload.subarray(0, 4);
        payload = payload.subarray(4, 4 + length);
        for (let i = 0; i < payload.length; i++) payload[i] ^= mask[i % 4];
      } else {
        payload = payload.subarray(0, length);
      }
      const msg = JSON.parse(payload.toString('utf8'));
      if (msg && msg.action === 'join' && msg.deviceId) {
        client.deviceId = String(msg.deviceId);
        send(socket, { event: 'joined', deviceId: client.deviceId });
      }
    } catch (_) {}
  });

  socket.on('close', () => clients.delete(client));
  socket.on('error', () => clients.delete(client));
});

server.listen(PORT, () => {
  console.log(`Realtime WS hub listening on :${PORT}`);
});
