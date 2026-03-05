import http from '@/api/http';

interface Response {
    token: string;
    socket: string;
    proxy?: boolean;
}

export default (server: string): Promise<Response> => {
    return new Promise((resolve, reject) => {
        http.get(`/api/client/servers/${server}/websocket`)
            .then(({ data }) =>
                resolve({
                    token: data.data.token,
                    socket: data.data.socket,
                    proxy: data.data.proxy === true,
                })
            )
            .catch(reject);
    });
};
