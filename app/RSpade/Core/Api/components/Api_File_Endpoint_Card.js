/**
 * Api_File_Endpoint_Card - Api_Endpoint_Card for an endpoint that takes multipart/form-data.
 *
 * Carries no behaviour of its own: the class exists so jqhtml can walk the prototype chain
 * from a slots-only template to the parent template it inherits, and so the element carries
 * both class names (the SCSS is Api_Endpoint_Card's, unchanged).
 *
 * args: endpoint (object) - one resolved catalog endpoint, declaring a `file` param.
 */
class Api_File_Endpoint_Card extends Api_Endpoint_Card {
}
