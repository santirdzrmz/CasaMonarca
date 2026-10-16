type AppIconProps = {
  className?: string
  name:
    | 'admin'
    | 'bundle'
    | 'chart'
    | 'copy'
    | 'dashboard'
    | 'delete'
    | 'document'
    | 'download'
    | 'history'
    | 'invite'
    | 'key'
    | 'keyReset'
    | 'login'
    | 'logging'
    | 'logout'
    | 'moveDown'
    | 'moveUp'
    | 'plus'
    | 'refresh'
    | 'sign'
    | 'suspend'
    | 'upload'
    | 'verify'
  size?: number
}

const commonProps = {
  fill: 'none',
  stroke: 'currentColor',
  strokeLinecap: 'round' as const,
  strokeLinejoin: 'round' as const,
  strokeWidth: 1.9,
}

export function AppIcon({
  className,
  name,
  size = 18,
}: AppIconProps) {
  return (
    <svg
      aria-hidden="true"
      className={className ?? 'app-icon'}
      height={size}
      viewBox="0 0 24 24"
      width={size}
      xmlns="http://www.w3.org/2000/svg"
    >
      {name === 'dashboard' ? (
        <>
          <rect {...commonProps} height="7" rx="1.4" width="7" x="3.5" y="3.5" />
          <rect {...commonProps} height="7" rx="1.4" width="7" x="13.5" y="3.5" />
          <rect {...commonProps} height="7" rx="1.4" width="7" x="3.5" y="13.5" />
          <rect {...commonProps} height="7" rx="1.4" width="7" x="13.5" y="13.5" />
        </>
      ) : null}

      {name === 'chart' ? (
        <>
          <path {...commonProps} d="M4 20h16" />
          <path {...commonProps} d="M7 16.5v-5" />
          <path {...commonProps} d="M12 16.5V6.5" />
          <path {...commonProps} d="M17 16.5v-8" />
        </>
      ) : null}

      {name === 'upload' ? (
        <>
          <path {...commonProps} d="M12 15V4.5" />
          <path {...commonProps} d="m7.5 9 4.5-4.5L16.5 9" />
          <path {...commonProps} d="M4 16.5v1.75A1.75 1.75 0 0 0 5.75 20h12.5A1.75 1.75 0 0 0 20 18.25V16.5" />
        </>
      ) : null}

      {name === 'document' ? (
        <>
          <path {...commonProps} d="M8 3.75h6.75L19.25 8v11.25A1.75 1.75 0 0 1 17.5 21h-9A1.75 1.75 0 0 1 6.75 19.25v-13.75A1.75 1.75 0 0 1 8.5 3.75Z" />
          <path {...commonProps} d="M14.5 3.75V8h4.25" />
          <path {...commonProps} d="M9 12h6" />
          <path {...commonProps} d="M9 15.5h6" />
        </>
      ) : null}

      {name === 'history' ? (
        <>
          <path {...commonProps} d="M4.5 12a7.5 7.5 0 1 0 2.2-5.3" />
          <path {...commonProps} d="M4.5 5.75V10h4.25" />
          <path {...commonProps} d="M12 8.5V12l2.5 1.75" />
        </>
      ) : null}

      {name === 'logging' ? (
        <>
          <path {...commonProps} d="M4 18.5h16" />
          <path {...commonProps} d="M7 18.5v-7" />
          <path {...commonProps} d="M12 18.5v-11" />
          <path {...commonProps} d="M17 18.5v-4.5" />
        </>
      ) : null}

      {name === 'admin' ? (
        <>
          <path {...commonProps} d="m12 3.5 7 2.9v5.1c0 4.4-2.7 7.8-7 9-4.3-1.2-7-4.6-7-9V6.4l7-2.9Z" />
          <path {...commonProps} d="m9.25 12 1.8 1.8 3.7-4.1" />
        </>
      ) : null}

      {name === 'invite' ? (
        <>
          <circle {...commonProps} cx="8.25" cy="9" r="2.5" />
          <path {...commonProps} d="M4.5 16.75c0-2.1 1.7-3.8 3.75-3.8s3.75 1.7 3.75 3.8" />
          <path {...commonProps} d="M14 12h6" />
          <path {...commonProps} d="M17 9v6" />
        </>
      ) : null}

      {name === 'refresh' ? (
        <>
          <path {...commonProps} d="M19 11a7 7 0 0 0-12-4.8" />
          <path {...commonProps} d="M5 6v4h4" />
          <path {...commonProps} d="M5 13a7 7 0 0 0 12 4.8" />
          <path {...commonProps} d="M19 18v-4h-4" />
        </>
      ) : null}

      {name === 'download' ? (
        <>
          <path {...commonProps} d="M12 4.5v10.5" />
          <path {...commonProps} d="m7.5 11.5 4.5 4.5 4.5-4.5" />
          <path {...commonProps} d="M4.5 19.5h15" />
        </>
      ) : null}

      {name === 'verify' ? (
        <>
          <path {...commonProps} d="m12 3.5 7 2.9v5.1c0 4.4-2.7 7.8-7 9-4.3-1.2-7-4.6-7-9V6.4l7-2.9Z" />
          <path {...commonProps} d="m9.25 12 1.8 1.8 3.7-4.1" />
        </>
      ) : null}

      {name === 'sign' ? (
        <>
          <path {...commonProps} d="m14.25 5.25 4.5 4.5" />
          <path {...commonProps} d="M5.5 18.5 6.75 14 15.5 5.25a1.6 1.6 0 0 1 2.25 0l1 1a1.6 1.6 0 0 1 0 2.25L10 17.25 5.5 18.5Z" />
          <path {...commonProps} d="M12.5 8.25 15.75 11.5" />
        </>
      ) : null}

      {name === 'delete' ? (
        <>
          <path {...commonProps} d="M4.75 7.25h14.5" />
          <path {...commonProps} d="M9.25 3.75h5.5" />
          <path {...commonProps} d="M8 7.25v11A1.75 1.75 0 0 0 9.75 20h4.5A1.75 1.75 0 0 0 16 18.25v-11" />
          <path {...commonProps} d="M10 10.25v6" />
          <path {...commonProps} d="M14 10.25v6" />
        </>
      ) : null}

      {name === 'suspend' ? (
        <>
          <circle {...commonProps} cx="12" cy="12" r="7.25" />
          <path {...commonProps} d="m7 17 10-10" />
        </>
      ) : null}

      {name === 'logout' ? (
        <>
          <path {...commonProps} d="M10.5 4.5H7.75A1.75 1.75 0 0 0 6 6.25v11.5A1.75 1.75 0 0 0 7.75 19.5h2.75" />
          <path {...commonProps} d="M13 8.25 17.75 12 13 15.75" />
          <path {...commonProps} d="M9 12h8.75" />
        </>
      ) : null}

      {name === 'login' ? (
        <>
          <path {...commonProps} d="M13.5 4.5h2.75A1.75 1.75 0 0 1 18 6.25v11.5A1.75 1.75 0 0 1 16.25 19.5H13.5" />
          <path {...commonProps} d="M11 8.25 6.25 12 11 15.75" />
          <path {...commonProps} d="M15 12H6.25" />
        </>
      ) : null}

      {name === 'key' ? (
        <>
          <circle {...commonProps} cx="8.25" cy="12" r="3.25" />
          <path {...commonProps} d="M11.5 12H20.25" />
          <path {...commonProps} d="M16.5 12v2.25" />
          <path {...commonProps} d="M18.75 12v1.5" />
        </>
      ) : null}

      {name === 'keyReset' ? (
        <>
          <path {...commonProps} d="M4.05 11a8 8 0 1 1 .5 4" />
          <path {...commonProps} d="M4.05 20v-5h5" />
        </>
      ) : null}

      {name === 'bundle' ? (
        <>
          <path {...commonProps} d="M7 6.5h8.5a1.75 1.75 0 0 1 1.75 1.75v9.25A1.75 1.75 0 0 1 15.5 19.25H7A1.75 1.75 0 0 1 5.25 17.5V8.25A1.75 1.75 0 0 1 7 6.5Z" />
          <path {...commonProps} d="M8.75 4.75h8.5a1.75 1.75 0 0 1 1.75 1.75V15" />
          <path {...commonProps} d="M8.5 10h5.5" />
          <path {...commonProps} d="M8.5 13h5.5" />
        </>
      ) : null}

      {name === 'copy' ? (
        <>
          <rect {...commonProps} height="11" rx="1.7" width="9.5" x="8.25" y="7.5" />
          <path {...commonProps} d="M15.75 7.5v-2A1.75 1.75 0 0 0 14 3.75H6.75A1.75 1.75 0 0 0 5 5.5v11A1.75 1.75 0 0 0 6.75 18.25H8.25" />
        </>
      ) : null}

      {name === 'plus' ? (
        <>
          <path {...commonProps} d="M12 5v14" />
          <path {...commonProps} d="M5 12h14" />
        </>
      ) : null}

      {name === 'moveUp' ? (
        <>
          <path {...commonProps} d="m6.5 14.5 5.5-5.5 5.5 5.5" />
        </>
      ) : null}

      {name === 'moveDown' ? (
        <>
          <path {...commonProps} d="m6.5 9.5 5.5 5.5 5.5-5.5" />
        </>
      ) : null}
    </svg>
  )
}

export type AppIconName = AppIconProps['name']
