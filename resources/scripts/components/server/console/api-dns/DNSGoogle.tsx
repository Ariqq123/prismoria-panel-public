import axios from 'axios';

const getIP = async (hostname: string) => {
    const responseFromDns = await axios.get(`https://dns.google/resolve?name=${hostname}`);
    const jsonDataFromDns = responseFromDns.data;
    if (jsonDataFromDns && jsonDataFromDns.Status === 0 && Array.isArray(jsonDataFromDns.Answer) && jsonDataFromDns.Answer.length > 0) {
        return jsonDataFromDns.Answer[0].data;
    }

    return null;
};

export default { getIP };
