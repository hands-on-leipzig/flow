import Keycloak from 'keycloak-js'

const keycloak = new Keycloak({
    url: 'https://sso.hands-on-technology.org/',        // Keycloak base URL
    realm: 'master',
    clientId: 'flow',
})

export default keycloak
