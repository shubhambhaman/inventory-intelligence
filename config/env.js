const requiredEnv = (name) => {
    const value = process.env[name];

    if (!value) {
        throw new Error(`Missing required environment variable: ${name}`);
    }

    return value;
};

const env = {
    app: {
        name: requiredEnv('APP_NAME'),
        environment: requiredEnv('APP_ENV'),
        debug: process.env.APP_DEBUG === 'true',
    },
};

module.exports = env;