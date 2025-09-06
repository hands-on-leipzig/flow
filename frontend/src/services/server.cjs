const WebSocket = require('ws');
const PORT = 3042;
const HOST = '0.0.0.0';

const server = new WebSocket.Server({host: HOST, port: PORT});

console.log(`WebSocket server started on ws://${HOST}:${PORT}/ws`);

const clients = new Set();

function broadcast(data) {
    const message = JSON.stringify(data);
    clients.forEach((client) => {
        if (client.readyState === WebSocket.OPEN) {
            client.send(message);
            console.log('Broadcasting message:', message);
        }
    });
}

server.on('connection', (ws) => {
    console.log('Client connected');
    clients.add(ws);

    ws.on('message', (message) => {
        console.log('Received message:', message);

        try {
            const msg = JSON.parse(message);
            switch (msg.type) {
                case 'register':
                    console.log(`Registered client: ${msg.clientId}`);
                    break;

                case 'saveSlides':
                    console.log('Received slides:', msg.slides);
                    broadcast({type: 'setSlides', slides: msg.slides});
                    break;
                case 'pushSlide':
                    broadcast({type: 'pushSlide', slide: msg.slide});
                default:
                    console.warn('Unknown message type:', msg.type);
            }
        } catch (error) {
            console.error('Failed to parse message:', error);
        }
    });

    ws.on('close', () => {
        console.log('Client disconnected');
        clients.delete(ws);
    });

    ws.on('error', (error) => {
        console.error('WebSocket error:', error);
    });
});