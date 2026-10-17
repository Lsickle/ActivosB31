'use client'

import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { settingsApi, type AppSettings } from 'app/features/activos/api'
import { expirySettingsSchema } from 'app/features/activos/schemas'
import { ApiValidationError } from 'app/lib/api-client'
import { apiFieldErrors, zodFieldErrors, type FieldErrors } from 'app/lib/form-errors'
import { useCan } from 'app/provider/auth'
import { FormField, fieldA11yProps } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'

const FIELD_ID = 'settings-expiry-alert-days'

// 005 D-005 / RN-030: ajustes globales. Ver = configuracion.ver (lo exige la
// pantalla); cambiar = configuracion.gestionar (FR-006).
export function SettingsTab() {
  const canManage = useCan('configuracion.gestionar')
  const [settings, setSettings] = useState<AppSettings | null>(null)
  const [loadError, setLoadError] = useState(false)
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  useEffect(() => {
    let cancelled = false
    settingsApi
      .get()
      .then(({ data }) => {
        if (!cancelled) setSettings(data)
      })
      .catch(() => {
        if (!cancelled) setLoadError(true)
      })
    return () => {
      cancelled = true
    }
  }, [])

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError(null)
    const formData = new FormData(event.currentTarget)
    const parsed = expirySettingsSchema.safeParse({ expiry_alert_days: String(formData.get('expiry_alert_days') ?? '') })
    if (!parsed.success) {
      setErrors(zodFieldErrors(parsed.error))
      return
    }

    setErrors({})
    setIsSubmitting(true)
    try {
      const { data } = await settingsApi.update(parsed.data)
      setSettings(data)
      toast.success('Ajustes guardados.')
    } catch (err) {
      if (err instanceof ApiValidationError) {
        setErrors(apiFieldErrors(err))
      } else {
        setFormError(err instanceof Error ? err.message : 'No fue posible guardar los ajustes.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  if (loadError) {
    return (
      <p role="alert" className="text-sm text-destructive">
        No fue posible cargar los ajustes.
      </p>
    )
  }

  if (!settings) {
    return <Skeleton className="h-40 w-full" />
  }

  return (
    <Card className="max-w-xl">
      <CardHeader>
        <CardTitle>Alertas de vencimiento</CardTitle>
        <CardDescription>
          Con cuántos días de anticipación se avisa que un activo está próximo a vencer (RN-030). Los mantenimientos usan la anticipación de cada plan.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-4">
          <FormField
            id={FIELD_ID}
            label="Anticipación de alertas de vencimiento (días)"
            error={errors.expiry_alert_days}
            description={canManage ? 'Entre 0 y 365 días. Por defecto, 30.' : 'Solo quien gestiona la configuración puede cambiarla.'}
          >
            <Input
              {...fieldA11yProps(FIELD_ID, errors.expiry_alert_days, true)}
              key={settings.expiry_alert_days}
              name="expiry_alert_days"
              type="number"
              inputMode="numeric"
              min={0}
              max={365}
              step={1}
              defaultValue={settings.expiry_alert_days}
              readOnly={!canManage}
              className="sm:max-w-40"
            />
          </FormField>
          {formError && (
            <p role="alert" className="text-sm text-destructive">
              {formError}
            </p>
          )}
          {canManage && (
            <div>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting ? 'Guardando…' : 'Guardar ajustes'}
              </Button>
            </div>
          )}
        </form>
      </CardContent>
    </Card>
  )
}
