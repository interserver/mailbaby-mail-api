# MailBaby API

API service for accessing the Mail.Baby services.

# API Sample Clients

Sample clients for the API are available in many languages

* https://github.com/interserver/mailbaby-api-samples

# Development

This is built on top of Webman, a high performance HTTP Service Framework for PHP based on [Workerman](https://github.com/walkor/workerman).

## API Specification

We are utilizing the OpenAPI (formerly known as Swagger) spec for this API.  It is basically the next evolution of SOAP API's with well defined functions, parameters, and responses.  While there are many editors out there I'm currently using SwaggerHub to do most of the editing of the spec.

* [SwaggerHub editor for MailBaby API Spec](https://app.swaggerhub.com/apis/InterServer/MailBaby/1.0.0)
* [Swagger/OpenAPI Spec Documentation](https://swagger.io/docs/specification/describing-responses/)

## Webman Framework

After testing *every* PHP library out there dealing with concurrent/asynchronous processing many times over the years I've found [Workerman](https://github.com/walkor/workerman) to be the overall best.  It has proven more stable and by far faster than the alternatives with the one big downside being that its documentation and code comments are all in Chinese.  [Webman](https://github.com/walkor/webman) is a fairly recently created web framework on top of [Workerman](https://github.com/walkor/workerman).  There had been many previously created frameworks based on workerman and while some were good Webman seemed to hit that perfect balance between ease-of-use and power.

The Chinese documentation is easily readable Using either the auto translate in Chrome or an addon like [Translate Web Pages](https://addons.mozilla.org/en-US/firefox/addon/traduzir-paginas-web/) for Firefox.

* [WebMan Manual](https://www.workerman.net/doc/webman) WebMan framework documentation

## Documentation

* [Illuminate Database Docs](https://laravel.com/docs/8.x/queries)
* [StopLight Elements](https://github.com/stoplightio/elements) OpenAPI Documentor
* [MailBaby Swagger-UI](https://api.mailbaby.net/doc/index.html)
* [MailBaby generator HTML2  Docs](https://mystage.interserver.net/html2/)
* [MailBaby generated PHP Client](https://github.com/interserver/mailbaby-client-php) PHP Client generated by the OpenAPI Generator/Swagger Codegen
* [Redoc - OpenAPI/Swagger-generated API Reference Documentation](https://github.com/Redocly/redoc)
* [RapiDoc - Custom-Element for OpenAPI Spec](https://github.com/mrin9/RapiDoc)
* [OpenDocumenter is a automatic documentation generator for OpenAPI v3 schemas. Simply provide your schema file in JSON or YAML, then sit back and enjoy the documentation. ](https://github.com/ouropencode/OpenDocumenter)
* [OpenAPI Explorer - OpenAPI Web component to generate a UI from the spec.](https://github.com/Rhosys/openapi-explorer)
* [oas3-api-snippet-enricher Enrich your OpenAPI 3.0 JSON with code samples ](https://github.com/cdwv/oas3-api-snippet-enricher/)
* [OpenAPI-Viewer - OpenApi viewer Implemented using Vue](https://github.com/mrin9/OpenAPI-Viewer)
* [LucyBot Documentation Starter - Interactive REST API Documentation ](https://github.com/LucyBot-Inc/documentation-starter)


Building Elements:

```
git clone git@github.com:stoplightio/elements.git
cd elements && \
dst="https://raw.githubusercontent.com/interserver/mailbaby-mail-api/master/public/spec/openapi.yaml" && \
for src in https://raw.githubusercontent.com/stoplightio/Public-APIs/master/reference/zoom/openapi.yaml https://api.apis.guru/v2/specs/github.com/1.1.4/openapi.yaml; do
  grep -r $src -l | xargs -n 1 sed s#"$src"#"$dst"#g -i
done && \
yarn && \
yarn build && \
for i in angular react-gatsby react-cra static-html; do
  yarn copy:$i && \
  yarn build:$i
done
```

## Code Samples

* [Swagger Codegen](https://github.com/swagger-api/swagger-codegen)
* [OpenAPI Generator](https://github.com/OpenAPITools/openapi-generator/)
* [httpsnippet](https://github.com/detain/httpsnippet)

## TODO

* Customize 404 Page
* Testing
* Additional error checking and handling
* Auto Updates
* Placing Orders

## URLs of interest in this project

* [OpenAPI Tools](https://openapi.tools/) Listing of OpenAPI relatd tools by category (Documentors, Parsers, Mockers, etc)
* [OpenAPI PSR-7 Message Validator](https://github.com/thephpleague/openapi-psr7-validator)
* [PHPMailer](https://github.com/PHPMailer/PHPMailer/) email sending library for PHP
* [Swagger UI](https://github.com/swagger-api/swagger-ui)
* [Swagger Editor](https://github.com/swagger-api/swagger-editor)
* [OpenAPI GUI](https://github.com/Mermade/openapi-gui)

## Benchmarks

https://www.techempower.com/benchmarks/#section=test&runid=9716e3cd-9e53-433c-b6c5-d2c48c9593c1&hw=ph&test=db&l=zg24n3-1r&a=2
![image](https://user-images.githubusercontent.com/6073368/96447814-120fc980-1245-11eb-938d-6ea408716c72.png)

## LICENSE

MIT
