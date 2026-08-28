export type Preference = {
  type: string;
  in_app: boolean;
  email: boolean;
  digest: "off" | "daily" | "weekly";
};

export type NotificationType = {
  key: string;
  label: string;
  /** Cannot be muted in app: being asked to decide is not optional. */
  alwaysInApp?: boolean;
};
