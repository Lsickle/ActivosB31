'use client'

import { useState } from 'react'
import { TriangleAlertIcon } from 'lucide-react'
import type { ConfigurationOptions } from 'app/features/configuracion/api'
import { employeesApi, type EmployeeRecord } from 'app/features/empleados/api'
import { DOCUMENT_TYPE_LABELS, DOCUMENT_TYPES, employeeFormSchema, todayIsoDate } from 'app/features/empleados/schemas'
import { ApiValidationError } from 'app/lib/api-client'
import { apiFieldErrors, zodFieldErrors, type FieldErrors } from 'app/lib/form-errors'
import { FormField, fieldA11yProps } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { CatalogSelectField } from '@/features/configuracion/CatalogFormDialog'
import { optionsPlaceholder, parentSelectItems } from '@/features/configuracion/useConfigurationOptions'

// Valor del select de compañía para "sin compañía" (company_id = null, FR-003).
const NO_COMPANY = 'none'

const DOCUMENT_TYPE_ITEMS = DOCUMENT_TYPES.map((type) => ({ value: type, label: DOCUMENT_TYPE_LABELS[type] }))

const SIZE_FIELDS = [
  { name: 'shirt_size', label: 'Talla de camisa' },
  { name: 'pants_size', label: 'Talla de pantalón' },
  { name: 'boot_size', label: 'Talla de botas' },
  { name: 'glove_size', label: 'Talla de guantes' },
] as const

// US1 / FR-003 / RN-021: registrar o editar un empleado. `employee` = null crea.
// Cargos y compañías salen de las opciones disponibles de Configuración (solo
// activos, FR-004); al editar se conserva el actual aunque esté inactivo (US2-1).
export function EmployeeFormDialog({
  open,
  onOpenChange,
  employee,
  options,
  optionsFailed,
  onSaved,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  employee: EmployeeRecord | null
  /** null mientras cargan o si fallaron (`optionsFailed`). */
  options: ConfigurationOptions | null
  optionsFailed: boolean
  onSaved: (employee: EmployeeRecord, created: boolean) => void
}) {
  const title = employee ? 'Editar empleado' : 'Nuevo empleado'
  const blocked = !employee && options !== null && options.positions.length === 0

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-2xl">
        {/* Se monta de nuevo en cada apertura: el formulario arranca con los datos del empleado. */}
        {open &&
          (blocked ? (
            <div className="flex flex-col gap-4">
              <DialogHeader>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription className="flex items-start gap-2">
                  <TriangleAlertIcon className="mt-0.5 size-4 shrink-0 text-amber-600" aria-hidden />
                  <span>Primero crea un cargo activo en Configuración → Cargos.</span>
                </DialogDescription>
              </DialogHeader>
              <DialogFooter>
                <Button type="button" onClick={() => onOpenChange(false)}>
                  Entendido
                </Button>
              </DialogFooter>
            </div>
          ) : (
            <EmployeeForm
              title={title}
              employee={employee}
              options={options}
              optionsFailed={optionsFailed}
              onCancel={() => onOpenChange(false)}
              onSaved={onSaved}
            />
          ))}
      </DialogContent>
    </Dialog>
  )
}

function optionalLabel(text: string) {
  return (
    <>
      {text} <span className="font-normal text-muted-foreground">(opcional)</span>
    </>
  )
}

function Section({ title, description, children }: { title: string; description?: string; children: React.ReactNode }) {
  return (
    <fieldset className="flex min-w-0 flex-col gap-3">
      <legend className="mb-1 text-sm font-semibold">{title}</legend>
      {description && <p className="-mt-2 text-xs text-muted-foreground">{description}</p>}
      {children}
    </fieldset>
  )
}

function EmployeeForm({
  title,
  employee,
  options,
  optionsFailed,
  onCancel,
  onSaved,
}: {
  title: string
  employee: EmployeeRecord | null
  options: ConfigurationOptions | null
  optionsFailed: boolean
  onCancel: () => void
  onSaved: (employee: EmployeeRecord, created: boolean) => void
}) {
  const isCreating = employee === null
  const [errors, setErrors] = useState<FieldErrors>({})
  const [formError, setFormError] = useState<string | null>(null)
  const [isSubmitting, setIsSubmitting] = useState(false)

  const positionItems = parentSelectItems(options?.positions ?? [], employee?.position, '(inactivo)')
  const companyItems = [
    { value: NO_COMPANY, label: 'Sin compañía' },
    ...parentSelectItems(options?.companies ?? [], employee?.company, '(inactiva)'),
  ]

  async function handleSubmit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setFormError(null)

    const formData = new FormData(event.currentTarget)
    const text = (name: string) => String(formData.get(name) ?? '')
    const companyValue = text('company_id')
    const parsed = employeeFormSchema.safeParse({
      document_type: text('document_type'),
      document_number: text('document_number'),
      first_name: text('first_name'),
      last_name: text('last_name'),
      position_id: Number(text('position_id')) || undefined,
      company_id: companyValue === '' || companyValue === NO_COMPANY ? null : Number(companyValue),
      phone: text('phone'),
      email: text('email'),
      hire_date: text('hire_date'),
      shirt_size: text('shirt_size'),
      pants_size: text('pants_size'),
      boot_size: text('boot_size'),
      glove_size: text('glove_size'),
    })
    if (!parsed.success) {
      setErrors(zodFieldErrors(parsed.error))
      return
    }

    setErrors({})
    setIsSubmitting(true)
    try {
      const { data } = isCreating ? await employeesApi.create(parsed.data) : await employeesApi.update(employee.id, parsed.data)
      onSaved(data, isCreating)
    } catch (err) {
      if (err instanceof ApiValidationError) {
        // US1-2: documento duplicado (tipo + número) llega como 422 del campo.
        setErrors(apiFieldErrors(err))
      } else {
        setFormError(err instanceof Error ? err.message : 'No fue posible guardar el empleado.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-5">
      <DialogHeader>
        <DialogTitle>{title}</DialogTitle>
        <DialogDescription>Datos personales protegidos (Ley 1581 de 2012): solo los ve quien tiene permiso sobre Empleados.</DialogDescription>
      </DialogHeader>

      <Section title="Identificación">
        <div className="grid gap-4 sm:grid-cols-2">
          <CatalogSelectField
            id="employee-document-type"
            name="document_type"
            label="Tipo de documento"
            placeholder="Selecciona un tipo"
            items={DOCUMENT_TYPE_ITEMS}
            defaultValue={employee?.document_type ?? 'CC'}
            error={errors.document_type}
          />
          <FormField
            id="employee-document-number"
            label="Número de documento"
            error={errors.document_number}
            description="Se guardan solo letras y números, sin puntos ni espacios."
          >
            <Input
              {...fieldA11yProps('employee-document-number', errors.document_number, true)}
              name="document_number"
              defaultValue={employee?.document_number ?? ''}
              autoComplete="off"
              spellCheck={false}
              maxLength={30}
              required
            />
          </FormField>
          <FormField id="employee-first-name" label="Nombres" error={errors.first_name}>
            <Input
              {...fieldA11yProps('employee-first-name', errors.first_name)}
              name="first_name"
              defaultValue={employee?.first_name ?? ''}
              autoComplete="off"
              maxLength={80}
              required
            />
          </FormField>
          <FormField id="employee-last-name" label="Apellidos" error={errors.last_name}>
            <Input
              {...fieldA11yProps('employee-last-name', errors.last_name)}
              name="last_name"
              defaultValue={employee?.last_name ?? ''}
              autoComplete="off"
              maxLength={80}
              required
            />
          </FormField>
        </div>
      </Section>

      <Section title="Cargo e ingreso">
        <div className="grid gap-4 sm:grid-cols-2">
          <CatalogSelectField
            id="employee-position"
            name="position_id"
            label="Cargo"
            placeholder={optionsPlaceholder(options, optionsFailed, 'Selecciona un cargo')}
            items={positionItems}
            defaultValue={employee ? String(employee.position.id) : null}
            error={errors.position_id}
          />
          <CatalogSelectField
            id="employee-company"
            name="company_id"
            label="Compañía"
            placeholder={optionsPlaceholder(options, optionsFailed, 'Sin compañía')}
            items={companyItems}
            defaultValue={employee?.company ? String(employee.company.id) : NO_COMPANY}
            error={errors.company_id}
          />
          <FormField id="employee-hire-date" label={optionalLabel('Fecha de ingreso')} error={errors.hire_date}>
            <Input
              {...fieldA11yProps('employee-hire-date', errors.hire_date)}
              name="hire_date"
              type="date"
              max={todayIsoDate()}
              defaultValue={employee?.hire_date ?? ''}
            />
          </FormField>
        </div>
      </Section>

      <Section title="Contacto">
        <div className="grid gap-4 sm:grid-cols-2">
          <FormField id="employee-phone" label={optionalLabel('Teléfono')} error={errors.phone}>
            <Input
              {...fieldA11yProps('employee-phone', errors.phone)}
              name="phone"
              type="tel"
              defaultValue={employee?.phone ?? ''}
              autoComplete="off"
              maxLength={30}
            />
          </FormField>
          <FormField id="employee-email" label={optionalLabel('Correo')} error={errors.email}>
            <Input
              {...fieldA11yProps('employee-email', errors.email)}
              name="email"
              type="email"
              defaultValue={employee?.email ?? ''}
              autoComplete="off"
              maxLength={255}
            />
          </FormField>
        </div>
      </Section>

      <Section title="Tallas de dotación" description="Opcionales. Texto corto, p. ej. M, 42 o 32x30.">
        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
          {SIZE_FIELDS.map((field) => {
            const id = `employee-${field.name.replace('_', '-')}`
            return (
              <FormField key={field.name} id={id} label={field.label} error={errors[field.name]}>
                <Input
                  {...fieldA11yProps(id, errors[field.name])}
                  name={field.name}
                  defaultValue={employee?.[field.name] ?? ''}
                  autoComplete="off"
                  maxLength={10}
                />
              </FormField>
            )
          })}
        </div>
      </Section>

      {formError && (
        <p role="alert" className="text-sm text-destructive">
          {formError}
        </p>
      )}

      <DialogFooter>
        <Button type="button" variant="outline" onClick={onCancel}>
          Cancelar
        </Button>
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? 'Guardando…' : isCreating ? 'Crear empleado' : 'Guardar'}
        </Button>
      </DialogFooter>
    </form>
  )
}
