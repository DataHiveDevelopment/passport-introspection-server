<?php

namespace DataHiveDevelopment\PassportIntrospectionServer\Http\Controllers;

use Lcobucci\JWT\Token;
use Lcobucci\JWT\Parser;
use BadMethodCallException;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Passport\Passport;
use Laravel\Passport\Token as PassportToken;
use Lcobucci\JWT\ValidationData;
use Illuminate\Support\Facades\Log;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\ClientRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Laravel\Passport\TokenRepository;

class IntrospectionController
{

     /**
     * @var \Lcobucci\JWT\Parser
     */
    private $jwt;

    /**
     * @var \Laravel\Passport\TokenRepository
     */
    private $tokenRepository;

    /**
     * @var \Laravel\Passport\ClientRepository
     */
    private $clientRepository;

    /**
     * Setup repositories, servers, and parsers.
     *
     * @param \Lcobucci\JWT\Parser                $jwt
     * @param \Laravel\Passport\TokenRepository   $tokenRepository
     * @param \Laravel\Passport\ClientRepository  $clientRepository
     */
    public function __construct(
        Parser $jwt,
        TokenRepository $tokenRepository,
        ClientRepository $clientRepository
    ) {
        $this->jwt = $jwt;
        $this->tokenRepository = $tokenRepository;
        $this->clientRepository = $clientRepository;
    }

    /**
     * Authorize the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     *
     * @throws \Illuminate\Auth\AuthorizationException
     */
    protected function authorize(Request $request)
    {
        // Extract the Client from token given in the authorization header
        // Since this route should be protected by middleware, we can skip some checks
        $token = $this->jwt->parse($request->bearerToken());
        $client = $this->clientRepository->findActive($token->getClaim('aud'));

        if (! $client) {
            $cid = $this->logError('The client that issues the bearer token could not be located.');
            throw new AuthorizationException('Unauthorized. Correlation ID: '.$cid, 401);
        }

        Log::debug('IntrospectionController\authorize: Client (ID): '.$client->name.'('.$client->id.')');

        // Does the canIntrospect() method exist on the client model?
        if (method_exists($client, 'canIntrospect')) {
            // Yes! Now does that return true?
            if ($client->canIntrospect()) {
                Log::debug('IntrospectionController\authorize: Client can introspect! (Model)');
                return;
            }
        }

        // Fallback to the 'can_introspect' attribute from the database.
        if ($client->can_introspect) {
            Log::debug('IntrospectionController\authorize: Client can introspect! (Database)');
            return;
        }

        $cid = $this->logError('The requesting client is not allowed to perform introspection');
        throw new AuthorizationException('Unauthorized. Correlation ID: '.$cid, 401);
    }

    /**
     * Handle a request for introspection.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     *
     * @throws BadMethodCallException|AuthorizationException
     */
    public function introspect(Request $request)
    {
        Log::debug('IntrospectionController\introspect(): Received introspection request. Parsing requesting client...');
        $this->authorize($request);
        
        // Do we have a token to introspect?
        if (! $request->token) {
            return $this->inactiveResponse();
        }

        /**
         * Parse the token string into an object we can work with
         *
         * @var \Lcobucci\JWT\Token      $token
         * @var \Laravel\Passport\Token  $passportToken
         */
        $token = $this->jwt->parse($request->token);
        $passportToken = $this->tokenRepository->find($token->getClaim('jti'));
        
        // Verify and validate the token
        if (! $this->isTokenValid($token)) {
            return $this->inactiveResponse();
        }

        // Base response, additional data may be added
        $json = [
            'active' => true,
            'scope' => trim(implode(' ', $token->getClaim('scopes'))),
            'client_id' => intval($token->getClaim('aud')), // The client ID that requested the token
            'token_type' => 'access_token', // Only support access token introspection at this time
            'exp' => intval($token->getClaim('exp')), // Expiration time
            'iat' => intval($token->getClaim('iat')), // Issued at
            'nbf' => intval($token->getClaim('nbf')), // Not valid before
            'sub' => intval($token->getClaim('sub')), // Subject (User's unique ID associated with the token, as provided by the UserProvider)
            'aud' => intval($token->getClaim('aud')), // Audience, Passport sets this to the client ID that requested the token
            'jti' => $token->getClaim('jti'), // Token Id
        ];

        // Do we have a 'sub' claim?
        if ($token->getClaim('sub')) {
            $user = $passportToken->user;

            // Fetch the unique ID of the user (this should NOT be the auto increment ID) and return it in an 'id' claim
            if (method_exists($user, 'getIntrospectionId')) {
                $json['id'] = $user->getIntrospectionId();
            } else {
                throw new BadMethodCallException('Method not defined "getIntrospectionId()"');
            }
        }

        return response()->json($json, 200);
    }

    /**
     * Verify and validate the token's signature and data.
     *
     * @param Token $token
     * @return bool|\Illuminate\Http\Response
     */
    protected function isTokenValid(Token $token)
    {
        // Validation Data for $token->validate() method
        $data = new ValidationData();
        $data->setCurrentTime(time());
        $data->setAudience($token->getClaim('aud'));
        $data->setSubject($token->getClaim('sub'));

        // Passport public key
        $publicKey = 'file://' . Passport::keyPath('oauth-public.key');

        // Verify the token signature
        // Validate the token is not expired and is after the 'nbf' (not before) claim
        if (
            ! $token->verify(new Sha256(), $publicKey) ||
            ! $token->validate($data)
        ) {
            return false;
        }

        // Confirm the token was not revoked
        if ($this->tokenRepository->isAccessTokenRevoked($token->getClaim('jti'))) {
            return false;
        }

        return true;
    }

    /**
     * Return an inactive token response, as JSON.
     *
     * @param  String  $correlationId Optional ID to return in the response
     * @return \Illuminate\Http\Response
     */
    protected function inactiveResponse(String $correlationId = null)
    {
        /**
         * A token is considered inactive if the following critera fails:
         *
         * - The token has expired
         * - The token has been revoked
         * - The token has failed signature validation
         */

        $json = ['active' => false];

        if ($correlationId) {
            $json['correlation_id'] = $correlationId;
        }

        return response()->json($json, 200);
    }

    /**
     * Log an error and return a correlation ID.
     *
     * @param  String  $message
     * @return String
     */
    protected function logError(String $message)
    {
        $correlationId = Str::uuid();
        Log::error('CID: ' . $correlationId . ' - ' . $message);

        return $correlationId;
    }
}
