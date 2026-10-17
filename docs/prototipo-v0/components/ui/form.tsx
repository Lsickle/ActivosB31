'use client'

import * as React from 'react'
import { Controller, FormProvider, useFormContext, type ControllerProps, type FieldPath, type FieldValues } from 'react-hook-form'
import { cn } from '@/lib/utils'
import { Label } from '@/components/ui/label'

const Form = FormProvider
const FormField = <TFieldValues extends FieldValues, TName extends FieldPath<TFieldValues>>(props: ControllerProps<TFieldValues, TName>) => <Controller {...props} />
const FormItem = React.forwardRef<HTMLDivElement, React.HTMLAttributes<HTMLDivElement>>(({ className, ...props }, ref) => <div ref={ref} className={cn('flex flex-col gap-2', className)} {...props} />)
FormItem.displayName = 'FormItem'
const FormLabel = React.forwardRef<React.ElementRef<typeof Label>, React.ComponentPropsWithoutRef<typeof Label>>(({ className, ...props }, ref) => <Label ref={ref} className={className} {...props} />)
FormLabel.displayName = 'FormLabel'
const FormControl = ({ children }: { children: React.ReactElement }) => children
const FormMessage = () => { const { formState } = useFormContext(); return <p className="text-xs font-medium text-destructive">{Object.values(formState.errors)[0]?.message as string | undefined}</p> }

export { Form, FormControl, FormField, FormItem, FormLabel, FormMessage }
