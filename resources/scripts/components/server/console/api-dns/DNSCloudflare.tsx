import axios from 'axios';

const getIP = async (hostname: string) => {
    const dnsRes = await axios.get(
        `https://cloudflare-dns.com/dns-query?name=${hostname}&type=A`,
        { headers: { Accept: 'application/dns-json' } }
    );
    const answer = dnsRes.data?.Answer?.find((a: any) => a.type === 1);
    if (answer) {
        return answer.data;
    }

    return null;
};

export default { getIP };
