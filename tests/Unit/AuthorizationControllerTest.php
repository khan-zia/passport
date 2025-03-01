<?php

namespace Laravel\Passport\Tests\Unit;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Exceptions\OAuthServerException;
use Laravel\Passport\Http\Controllers\AuthorizationController;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use Laravel\Passport\TokenRepository;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\Exception\OAuthServerException as LeagueException;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Mockery as m;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class AuthorizationControllerTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
    }

    public function test_authorization_view_is_presented()
    {
        Passport::tokensCan([
            'scope-1' => 'description',
        ]);

        $server = m::mock(AuthorizationServer::class);
        $response = m::mock(ResponseFactory::class);

        $controller = new AuthorizationController($server, $response);

        $server->shouldReceive('validateAuthorizationRequest')->andReturn($authRequest = m::mock());

        $request = m::mock(Request::class);
        $request->shouldReceive('session')->andReturn($session = m::mock());
        $session->shouldReceive('put')->withSomeOfArgs('authToken');
        $session->shouldReceive('put')->with('authRequest', $authRequest);
        $request->shouldReceive('user')->andReturn($user = m::mock());
        $request->shouldReceive('get')->with('prompt')->andReturn(null);

        $authRequest->shouldReceive('getClient->getIdentifier')->andReturn(1);
        $authRequest->shouldReceive('getScopes')->andReturn([new Scope('scope-1')]);

        $clients = m::mock(ClientRepository::class);
        $clients->shouldReceive('find')->with(1)->andReturn($client = m::mock(Client::class));

        $client->shouldReceive('skipsAuthorization')->andReturn(false);

        $response->shouldReceive('view')->once()->andReturnUsing(function ($view, $data) use ($client, $user) {
            $this->assertSame('passport::authorize', $view);
            $this->assertEquals($client, $data['client']);
            $this->assertEquals($user, $data['user']);
            $this->assertSame('description', $data['scopes'][0]->description);

            return 'view';
        });

        $tokens = m::mock(TokenRepository::class);
        $tokens->shouldReceive('findValidToken')->with($user, $client)->andReturnNull();

        $this->assertSame('view', $controller->authorize(
            m::mock(ServerRequestInterface::class), $request, $clients, $tokens
        ));
    }

    public function test_authorization_exceptions_are_handled()
    {
        $server = m::mock(AuthorizationServer::class);
        $response = m::mock(ResponseFactory::class);

        $controller = new AuthorizationController($server, $response);

        $server->shouldReceive('validateAuthorizationRequest')->andThrow(LeagueException::invalidCredentials());

        $request = m::mock(Request::class);
        $request->shouldReceive('session')->andReturn($session = m::mock());

        $clients = m::mock(ClientRepository::class);
        $tokens = m::mock(TokenRepository::class);

        $this->expectException(OAuthServerException::class);

        $controller->authorize(
            m::mock(ServerRequestInterface::class), $request, $clients, $tokens
        );
    }

    public function test_request_is_approved_if_valid_token_exists()
    {
        Passport::tokensCan([
            'scope-1' => 'description',
        ]);

        $server = m::mock(AuthorizationServer::class);
        $response = m::mock(ResponseFactory::class);

        $controller = new AuthorizationController($server, $response);
        $psrResponse = new Response();
        $psrResponse->getBody()->write('approved');
        $server->shouldReceive('validateAuthorizationRequest')
            ->andReturn($authRequest = m::mock(AuthorizationRequest::class));
        $server->shouldReceive('completeAuthorizationRequest')
            ->with($authRequest, m::type(ResponseInterface::class))
            ->andReturn($psrResponse);

        $request = m::mock(Request::class);
        $request->shouldReceive('user')->once()->andReturn($user = m::mock());
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $request->shouldNotReceive('session');
        $request->shouldReceive('get')->with('prompt')->andReturn(null);

        $authRequest->shouldReceive('getClient->getIdentifier')->once()->andReturn(1);
        $authRequest->shouldReceive('getScopes')->once()->andReturn([new Scope('scope-1')]);
        $authRequest->shouldReceive('setUser')->once()->andReturnNull();
        $authRequest->shouldReceive('setAuthorizationApproved')->once()->with(true);

        $clients = m::mock(ClientRepository::class);
        $clients->shouldReceive('find')->with(1)->andReturn($client = m::mock(Client::class));

        $client->shouldReceive('skipsAuthorization')->andReturn(false);

        $tokens = m::mock(TokenRepository::class);
        $tokens->shouldReceive('findValidToken')
            ->with($user, $client)
            ->andReturn($token = m::mock(Token::class));
        $token->shouldReceive('getAttribute')->with('scopes')->andReturn(['scope-1']);

        $this->assertSame('approved', $controller->authorize(
            m::mock(ServerRequestInterface::class), $request, $clients, $tokens
        )->getContent());
    }

    public function test_request_is_approved_if_client_can_skip_authorization()
    {
        Passport::tokensCan([
            'scope-1' => 'description',
        ]);

        $server = m::mock(AuthorizationServer::class);
        $response = m::mock(ResponseFactory::class);

        $controller = new AuthorizationController($server, $response);
        $psrResponse = new Response();
        $psrResponse->getBody()->write('approved');
        $server->shouldReceive('validateAuthorizationRequest')
            ->andReturn($authRequest = m::mock(AuthorizationRequest::class));
        $server->shouldReceive('completeAuthorizationRequest')
            ->with($authRequest, m::type(ResponseInterface::class))
            ->andReturn($psrResponse);

        $request = m::mock(Request::class);
        $request->shouldReceive('user')->once()->andReturn($user = m::mock());
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $request->shouldNotReceive('session');
        $request->shouldReceive('get')->with('prompt')->andReturn(null);

        $authRequest->shouldReceive('getClient->getIdentifier')->once()->andReturn(1);
        $authRequest->shouldReceive('getScopes')->once()->andReturn([new Scope('scope-1')]);
        $authRequest->shouldReceive('setUser')->once()->andReturnNull();
        $authRequest->shouldReceive('setAuthorizationApproved')->once()->with(true);

        $clients = m::mock(ClientRepository::class);
        $clients->shouldReceive('find')->with(1)->andReturn($client = m::mock(Client::class));

        $client->shouldReceive('skipsAuthorization')->andReturn(true);

        $tokens = m::mock(TokenRepository::class);
        $tokens->shouldReceive('findValidToken')
            ->with($user, $client)
            ->andReturnNull();

        $this->assertSame('approved', $controller->authorize(
            m::mock(ServerRequestInterface::class), $request, $clients, $tokens
        )->getContent());
    }

    public function test_authorization_view_is_presented_if_request_has_prompt_equals_to_consent()
    {
        Passport::tokensCan([
            'scope-1' => 'description',
        ]);

        $server = m::mock(AuthorizationServer::class);
        $response = m::mock(ResponseFactory::class);

        $controller = new AuthorizationController($server, $response);
        $server->shouldReceive('validateAuthorizationRequest')
            ->andReturn($authRequest = m::mock(AuthorizationRequest::class));

        $request = m::mock(Request::class);
        $request->shouldReceive('session')->andReturn($session = m::mock());
        $session->shouldReceive('put')->withSomeOfArgs('authToken');
        $session->shouldReceive('put')->with('authRequest', $authRequest);
        $request->shouldReceive('user')->andReturn($user = m::mock());
        $request->shouldReceive('get')->with('prompt')->andReturn('consent');

        $authRequest->shouldReceive('getClient->getIdentifier')->once()->andReturn(1);
        $authRequest->shouldReceive('getScopes')->once()->andReturn([new Scope('scope-1')]);

        $clients = m::mock(ClientRepository::class);
        $clients->shouldReceive('find')->with(1)->andReturn($client = m::mock(Client::class));
        $client->shouldReceive('skipsAuthorization')->andReturn(false);

        $tokens = m::mock(TokenRepository::class);
        $tokens->shouldNotReceive('findValidToken');

        $response->shouldReceive('view')->once()->andReturnUsing(function ($view, $data) use ($client, $user) {
            $this->assertSame('passport::authorize', $view);
            $this->assertEquals($client, $data['client']);
            $this->assertEquals($user, $data['user']);
            $this->assertSame('description', $data['scopes'][0]->description);

            return 'view';
        });

        $this->assertSame('view', $controller->authorize(
            m::mock(ServerRequestInterface::class), $request, $clients, $tokens
        ));
    }

    public function test_authorization_denied_if_request_has_prompt_equals_to_none()
    {
        $this->expectException('Laravel\Passport\Exceptions\OAuthServerException');

        Passport::tokensCan([
            'scope-1' => 'description',
        ]);

        $server = m::mock(AuthorizationServer::class);
        $response = m::mock(ResponseFactory::class);

        $controller = new AuthorizationController($server, $response);
        $server->shouldReceive('validateAuthorizationRequest')
            ->andReturn($authRequest = m::mock(AuthorizationRequest::class));
        $server->shouldReceive('completeAuthorizationRequest')
            ->with($authRequest, m::type(ResponseInterface::class))
            ->once()
            ->andThrow('League\OAuth2\Server\Exception\OAuthServerException');

        $request = m::mock(Request::class);
        $request->shouldReceive('user')->andReturn($user = m::mock());
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $request->shouldReceive('get')->with('prompt')->andReturn('none');

        $authRequest->shouldReceive('getClient->getIdentifier')->once()->andReturn(1);
        $authRequest->shouldReceive('getScopes')->once()->andReturn([new Scope('scope-1')]);
        $authRequest->shouldReceive('setUser')->once()->andReturnNull();
        $authRequest->shouldReceive('setAuthorizationApproved')->once()->with(false);

        $clients = m::mock(ClientRepository::class);
        $clients->shouldReceive('find')->with(1)->andReturn($client = m::mock(Client::class));
        $client->shouldReceive('skipsAuthorization')->andReturn(false);

        $tokens = m::mock(TokenRepository::class);
        $tokens->shouldReceive('findValidToken')
            ->with($user, $client)
            ->andReturnNull();

        $controller->authorize(
            m::mock(ServerRequestInterface::class), $request, $clients, $tokens
        );
    }
}
