type SessionLineItem = {
  price_data: {
    currency: string
    product_data: { name: string; description?: string }
    unit_amount: number
  }
  quantity: number
}

export async function createCheckoutSession(
  secretKey: string,
  params: {
    items: SessionLineItem[]
    successUrl: string
    cancelUrl: string
    clientReferenceId: string
    currency: string
    customerEmail?: string
  }
): Promise<{ id: string; url: string | null }> {
  const body = new URLSearchParams()
  for (const [i, item] of params.items.entries()) {
    body.set(`line_items[${i}][price_data][currency]`, item.price_data.currency)
    body.set(`line_items[${i}][price_data][product_data][name]`, item.price_data.product_data.name)
    if (item.price_data.product_data.description) {
      body.set(`line_items[${i}][price_data][product_data][description]`, item.price_data.product_data.description)
    }
    body.set(`line_items[${i}][price_data][unit_amount]`, String(item.price_data.unit_amount))
    body.set(`line_items[${i}][quantity]`, String(item.quantity))
  }
  body.set('mode', 'payment')
  body.set('success_url', params.successUrl)
  body.set('cancel_url', params.cancelUrl)
  body.set('client_reference_id', params.clientReferenceId)
  if (params.customerEmail) body.set('customer_email', params.customerEmail)

  const response = await fetch('https://api.stripe.com/v1/checkout/sessions', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${secretKey}`,
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body,
  })

  if (!response.ok) {
    throw new Error(`Stripe session create failed: ${response.status} ${await response.text()}`)
  }

  const json = await response.json() as { id: string; url: string | null }
  return { id: json.id, url: json.url }
}
