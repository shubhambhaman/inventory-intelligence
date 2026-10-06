const env = require('./config/env');

console.log(`${env.app.name} is starting...`);
console.log(`Environment: ${env.app.environment}`);